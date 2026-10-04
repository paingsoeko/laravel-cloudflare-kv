<?php

declare(strict_types=1);

namespace Kopaing\CloudflareKV;

use Closure;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Kopaing\CloudflareKV\Contracts\CloudflareKVClientInterface;
use Kopaing\CloudflareKV\Events\CloudflareKVRequestCompleted;
use Kopaing\CloudflareKV\Events\CloudflareKVRequestFailed;
use Kopaing\CloudflareKV\Events\CloudflareKVRequestRetrying;
use Kopaing\CloudflareKV\Exceptions\CloudflareKVAuthenticationException;
use Kopaing\CloudflareKV\Exceptions\CloudflareKVConnectionException;
use Kopaing\CloudflareKV\Exceptions\CloudflareKVException;
use Kopaing\CloudflareKV\Exceptions\CloudflareKVLimitExceededException;
use Kopaing\CloudflareKV\Exceptions\CloudflareKVRateLimitException;
use Kopaing\CloudflareKV\Exceptions\CloudflareKVRequestException;
use Kopaing\CloudflareKV\Support\KeyFormatter;
use Kopaing\CloudflareKV\Support\RetryPolicy;
use Kopaing\CloudflareKV\Support\StoreConfig;
use SensitiveParameter;

/**
 * Cloudflare Workers KV REST API client for a single namespace.
 *
 * Limits below come from the official documentation (checked 2026-10):
 *  - https://developers.cloudflare.com/kv/platform/limits/
 *  - https://developers.cloudflare.com/api/resources/kv/subresources/namespaces/
 */
final class CloudflareKVClient implements CloudflareKVClientInterface
{
    /** Maximum value size: 25 MiB. */
    public const MAX_VALUE_BYTES = 25 * 1024 * 1024;

    /** expiration_ttl must be at least 60 seconds. */
    public const MIN_EXPIRATION_TTL = 60;

    /** POST /bulk/get accepts at most 100 keys. */
    public const MAX_BULK_GET_KEYS = 100;

    /** PUT /bulk writes at most 10,000 pairs per request. */
    public const MAX_BULK_WRITE_PAIRS = 10_000;

    /** POST /bulk/delete deletes at most 10,000 keys per request. */
    public const MAX_BULK_DELETE_KEYS = 10_000;

    /** A bulk write request must be 100 MB or less (decimal megabytes, the conservative reading). */
    public const MAX_BULK_REQUEST_BYTES = 100_000_000;

    /** GET /keys accepts a limit between 10 and 1000. */
    public const LIST_LIMIT_MIN = 10;

    public const LIST_LIMIT_MAX = 1000;

    private readonly string $namespaceUrl;

    public function __construct(
        private readonly HttpFactory $http,
        string $accountId,
        string $namespaceId,
        #[SensitiveParameter] private readonly string $apiToken,
        private readonly RetryPolicy $retry = new RetryPolicy(),
        private readonly float $timeout = 10.0,
        private readonly float $connectTimeout = 5.0,
        private readonly ?Dispatcher $events = null,
        string $baseUrl = StoreConfig::DEFAULT_BASE_URL,
    ) {
        $this->namespaceUrl = sprintf(
            '%s/accounts/%s/storage/kv/namespaces/%s',
            rtrim($baseUrl, '/'),
            rawurlencode($accountId),
            rawurlencode($namespaceId),
        );
    }

    public static function fromConfig(
        HttpFactory $http,
        StoreConfig $config,
        ?Dispatcher $events = null,
        ?RetryPolicy $retry = null,
    ): self {
        return new self(
            http: $http,
            accountId: $config->accountId,
            namespaceId: $config->namespaceId,
            apiToken: $config->apiToken,
            retry: $retry ?? new RetryPolicy($config->retryTimes, $config->retrySleepMs, $config->retryMaxSleepMs),
            timeout: $config->timeout,
            connectTimeout: $config->connectTimeout,
            events: $config->events ? $events : null,
            baseUrl: $config->baseUrl,
        );
    }

    public function get(string $key): ?string
    {
        $this->assertKey($key);

        $response = $this->send(
            'get',
            fn (PendingRequest $request): Response => $request->get($this->valueUrl($key)),
            acceptStatuses: [404],
        );

        // A 404 from the values endpoint means "no such key" (or the key has expired).
        return $response->status() === 404 ? null : $response->body();
    }

    public function getMany(array $keys): array
    {
        $keys = array_values(array_unique($keys));
        $results = [];

        foreach ($keys as $key) {
            $this->assertKey($key);
        }

        foreach (array_chunk($keys, self::MAX_BULK_GET_KEYS) as $chunk) {
            $response = $this->send(
                'bulk_get',
                fn (PendingRequest $request): Response => $request
                    ->acceptJson()
                    ->post($this->namespaceUrl.'/bulk/get', ['keys' => $chunk, 'type' => 'text']),
                acceptStatuses: [413],
            );

            // The bulk response is capped at 25 MB; fall back to single reads for this chunk.
            if ($response->status() === 413) {
                foreach ($chunk as $key) {
                    $results[$key] = $this->get($key);
                }

                continue;
            }

            $values = self::arrayAt($this->decodeEnvelope('bulk_get', $response), 'result')['values'] ?? null;

            if (! is_array($values)) {
                throw $this->invalidResponse('bulk_get', $response, 'missing "result.values"');
            }

            foreach ($chunk as $key) {
                $value = $values[$key] ?? null;

                if ($value !== null && ! is_string($value)) {
                    throw $this->invalidResponse('bulk_get', $response, 'non-string value for type=text');
                }

                $results[$key] = $value;
            }
        }

        return $results;
    }

    public function put(string $key, string $value, ?int $ttl = null): void
    {
        $this->assertKey($key);
        $this->assertValue($value);
        $this->assertTtl($ttl);

        $url = $this->valueUrl($key).($ttl === null ? '' : '?expiration_ttl='.$ttl);

        $response = $this->send(
            'put',
            fn (PendingRequest $request): Response => $request
                ->acceptJson()
                ->withBody($value, 'application/octet-stream')
                ->put($url),
        );

        $this->decodeEnvelope('put', $response);
    }

    public function putMany(array $values, ?int $ttl = null): array
    {
        $this->assertTtl($ttl);

        $items = [];

        foreach ($values as $key => $value) {
            $key = (string) $key;
            $this->assertKey($key);
            $this->assertValue($value);

            $item = ['key' => $key, 'value' => $value];

            if ($ttl !== null) {
                $item['expiration_ttl'] = $ttl;
            }

            $items[$key] = json_encode($item, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $failed = [];

        foreach ($this->bulkWriteBatches($items) as $batch) {
            $failed = [...$failed, ...$this->sendBulkWithPartialRetry(
                'bulk_put',
                $batch,
                fn (array $keys): Response => $this->send(
                    'bulk_put',
                    fn (PendingRequest $request): Response => $request
                        ->acceptJson()
                        ->withBody($this->jsonList($items, $keys), 'application/json')
                        ->put($this->namespaceUrl.'/bulk'),
                ),
            )];
        }

        return $failed;
    }

    public function delete(string $key): void
    {
        $this->assertKey($key);

        $response = $this->send(
            'delete',
            fn (PendingRequest $request): Response => $request->acceptJson()->delete($this->valueUrl($key)),
            acceptStatuses: [404],
        );

        if ($response->status() !== 404) {
            $this->decodeEnvelope('delete', $response);
        }
    }

    public function deleteMany(array $keys): array
    {
        $keys = array_values(array_unique($keys));

        foreach ($keys as $key) {
            $this->assertKey($key);
        }

        $failed = [];

        foreach (array_chunk($keys, self::MAX_BULK_DELETE_KEYS) as $chunk) {
            $failed = [...$failed, ...$this->sendBulkWithPartialRetry(
                'bulk_delete',
                $chunk,
                fn (array $batch): Response => $this->send(
                    'bulk_delete',
                    fn (PendingRequest $request): Response => $request
                        ->acceptJson()
                        ->withBody(json_encode($batch, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'application/json')
                        ->post($this->namespaceUrl.'/bulk/delete'),
                ),
            )];
        }

        return $failed;
    }

    public function listKeys(string $prefix, ?string $cursor = null, int $limit = self::LIST_LIMIT_MAX): array
    {
        $query = ['limit' => max(self::LIST_LIMIT_MIN, min(self::LIST_LIMIT_MAX, $limit))];

        if ($prefix !== '') {
            $query['prefix'] = $prefix;
        }

        if ($cursor !== null && $cursor !== '') {
            $query['cursor'] = $cursor;
        }

        $response = $this->send(
            'list_keys',
            fn (PendingRequest $request): Response => $request->acceptJson()->get($this->namespaceUrl.'/keys', $query),
        );

        $json = $this->decodeEnvelope('list_keys', $response);

        if (! is_array($json['result'] ?? null)) {
            throw $this->invalidResponse('list_keys', $response, 'missing "result"');
        }

        $keys = [];

        foreach ($json['result'] as $entry) {
            if (! is_array($entry) || ! is_string($entry['name'] ?? null)) {
                throw $this->invalidResponse('list_keys', $response, 'key entry without a name');
            }

            $keys[] = $entry['name'];
        }

        $next = self::arrayAt($json, 'result_info')['cursor'] ?? null;

        return ['keys' => $keys, 'cursor' => is_string($next) && $next !== '' ? $next : null];
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['namespaceUrl' => $this->namespaceUrl, 'apiToken' => '[redacted]'];
    }

    /**
     * Send a request with bounded retries for transient failures.
     *
     * @param  Closure(PendingRequest): Response  $call
     * @param  list<int>  $acceptStatuses  Non-2xx statuses the caller handles itself.
     */
    private function send(string $operation, Closure $call, array $acceptStatuses = []): Response
    {
        $started = hrtime(true);
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                $response = $call($this->newRequest());
            } catch (ConnectionException|TransferException $e) {
                if ($this->retryAfterFailure($operation, $attempt, 'connection', null)) {
                    continue;
                }

                // The transport exception is deliberately not chained: it holds the PSR-7
                // request, including the Authorization header, and could end up in error reports.
                $this->fail($operation, new CloudflareKVConnectionException(
                    sprintf('Could not reach the Cloudflare API during [%s]: %s', $operation, $this->redact($e->getMessage())),
                ), null, $started, $attempt);
            }

            $status = $response->status();

            if ($response->successful() || in_array($status, $acceptStatuses, true)) {
                $this->events?->dispatch(new CloudflareKVRequestCompleted(
                    $operation,
                    $status,
                    self::elapsedMs($started),
                    $attempt,
                ));

                return $response;
            }

            if (RetryPolicy::isRetryableStatus($status)) {
                $retryAfter = $status === 429 ? RetryPolicy::parseRetryAfter($response->header('Retry-After') ?: null) : null;

                if ($this->retryAfterFailure($operation, $attempt, 'http_'.$status, $retryAfter)) {
                    continue;
                }
            }

            $this->fail($operation, $this->exceptionFor($operation, $response), $status, $started, $attempt);
        }
    }

    private function retryAfterFailure(string $operation, int $attempt, string $reason, ?int $retryAfter): bool
    {
        if (! $this->retry->canRetry($attempt, idempotent: true)) {
            return false;
        }

        $delay = $this->retry->delayFor($attempt, $retryAfter);

        if ($delay === null) {
            return false;
        }

        $this->events?->dispatch(new CloudflareKVRequestRetrying($operation, $attempt, $delay, $reason));
        $this->retry->sleep($delay);

        return true;
    }

    /**
     * Run a bulk request and re-send only the keys Cloudflare reports as unsuccessful.
     *
     * @param  list<string>  $keys
     * @param  Closure(list<string>): Response  $sendBatch
     * @return list<string> Keys that still failed after the retry budget.
     */
    private function sendBulkWithPartialRetry(string $operation, array $keys, Closure $sendBatch): array
    {
        $round = 0;

        while (true) {
            $round++;

            $response = $sendBatch($keys);
            $json = $this->decodeEnvelope($operation, $response);
            $reported = self::arrayAt($json, 'result')['unsuccessful_keys'] ?? [];

            if (! is_array($reported)) {
                throw $this->invalidResponse($operation, $response, 'invalid "unsuccessful_keys"');
            }

            // Only trust names we actually sent.
            $unsuccessful = array_values(array_intersect($keys, array_filter($reported, 'is_string')));

            if ($unsuccessful === []) {
                return [];
            }

            $delay = $this->retry->canRetry($round, idempotent: true) ? $this->retry->delayFor($round) : null;

            if ($delay === null) {
                return $unsuccessful;
            }

            $this->events?->dispatch(new CloudflareKVRequestRetrying($operation, $round, $delay, 'partial_failure'));
            $this->retry->sleep($delay);

            $keys = $unsuccessful;
        }
    }

    /**
     * Split encoded bulk items so each request respects the pair-count and body-size limits.
     *
     * @param  array<string, string>  $items  Storage key => JSON-encoded item.
     * @return list<list<string>>
     */
    private function bulkWriteBatches(array $items): array
    {
        $batches = [];
        $current = [];
        $bytes = 2;

        foreach ($items as $key => $json) {
            $size = strlen($json) + 1;

            if ($current !== [] && (count($current) >= self::MAX_BULK_WRITE_PAIRS || $bytes + $size > self::MAX_BULK_REQUEST_BYTES)) {
                $batches[] = $current;
                $current = [];
                $bytes = 2;
            }

            $current[] = (string) $key;
            $bytes += $size;
        }

        if ($current !== []) {
            $batches[] = $current;
        }

        return $batches;
    }

    /**
     * @param  array<string, string>  $items
     * @param  list<string>  $keys
     */
    private function jsonList(array $items, array $keys): string
    {
        return '['.implode(',', array_map(static fn (string $key): string => $items[$key], $keys)).']';
    }

    private function newRequest(): PendingRequest
    {
        return $this->http
            ->withToken($this->apiToken)
            ->withUserAgent('kopaing/laravel-cloudflare-kv')
            // Guzzle options rather than timeout()/connectTimeout(): early Laravel 11
            // releases type those as int and would reject fractional seconds.
            ->withOptions(['timeout' => $this->timeout, 'connect_timeout' => $this->connectTimeout]);
    }

    private function valueUrl(string $key): string
    {
        return $this->namespaceUrl.'/values/'.rawurlencode($key);
    }

    /**
     * Decode a standard Cloudflare v4 JSON envelope and require success=true.
     *
     * @return array<mixed>
     */
    private function decodeEnvelope(string $operation, Response $response): array
    {
        $json = json_decode($response->body(), true);

        if (! is_array($json)) {
            throw $this->invalidResponse($operation, $response, 'body is not JSON');
        }

        if (($json['success'] ?? null) !== true) {
            throw $this->exceptionFor($operation, $response);
        }

        return $json;
    }

    /**
     * The array stored under $key, or an empty array ("result" is null for some writes).
     *
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    private static function arrayAt(array $data, string $key): array
    {
        return is_array($data[$key] ?? null) ? $data[$key] : [];
    }

    private function exceptionFor(string $operation, Response $response): CloudflareKVRequestException
    {
        $status = $response->status();
        $errors = $this->errorsFrom($response);

        $summary = $errors === []
            ? 'no error details returned'
            : implode('; ', array_map(
                static fn (array $error): string => ($error['code'] === null ? '' : '['.$error['code'].'] ').$error['message'],
                $errors,
            ));

        $message = $this->redact(sprintf('Cloudflare KV [%s] failed with HTTP %d: %s', $operation, $status, $summary));

        return match (true) {
            $status === 401, $status === 403 => new CloudflareKVAuthenticationException(
                $message.'. Check the API token and its "Workers KV Storage" permission.',
                $operation,
                $status,
                $errors,
            ),
            $status === 429 => new CloudflareKVRateLimitException(
                $message,
                $operation,
                RetryPolicy::parseRetryAfter($response->header('Retry-After') ?: null),
                $errors,
            ),
            default => new CloudflareKVRequestException($message, $operation, $status, $errors),
        };
    }

    /**
     * @return list<array{code: int|null, message: string}>
     */
    private function errorsFrom(Response $response): array
    {
        $json = json_decode($response->body(), true);
        $errors = [];

        if (! is_array($json) || ! is_array($json['errors'] ?? null)) {
            return [];
        }

        foreach ($json['errors'] as $error) {
            if (! is_array($error)) {
                continue;
            }

            $errors[] = [
                'code' => is_int($error['code'] ?? null) ? $error['code'] : null,
                'message' => mb_strimwidth(is_string($error['message'] ?? null) ? $error['message'] : 'unknown error', 0, 300, '...'),
            ];
        }

        return $errors;
    }

    private function invalidResponse(string $operation, Response $response, string $reason): CloudflareKVRequestException
    {
        return new CloudflareKVRequestException(
            sprintf('Cloudflare KV [%s] returned an unexpected response (HTTP %d): %s.', $operation, $response->status(), $reason),
            $operation,
            $response->status(),
        );
    }

    private function fail(string $operation, CloudflareKVException $exception, ?int $status, int $started, int $attempts): never
    {
        $this->events?->dispatch(new CloudflareKVRequestFailed(
            $operation,
            $exception::class,
            $status,
            self::elapsedMs($started),
            $attempts,
        ));

        throw $exception;
    }

    private function redact(string $message): string
    {
        return str_replace($this->apiToken, '[redacted]', $message);
    }

    private function assertKey(string $key): void
    {
        $bytes = strlen($key);

        if ($bytes === 0 || $bytes > KeyFormatter::MAX_KEY_BYTES) {
            throw new CloudflareKVLimitExceededException(sprintf(
                'Cloudflare KV keys must be between 1 and %d bytes; got %d bytes.',
                KeyFormatter::MAX_KEY_BYTES,
                $bytes,
            ));
        }
    }

    private function assertValue(string $value): void
    {
        if (strlen($value) > self::MAX_VALUE_BYTES) {
            throw new CloudflareKVLimitExceededException(sprintf(
                'Cloudflare KV values may be at most %d bytes (25 MiB); the encoded value is %d bytes.',
                self::MAX_VALUE_BYTES,
                strlen($value),
            ));
        }
    }

    private function assertTtl(?int $ttl): void
    {
        if ($ttl !== null && $ttl < self::MIN_EXPIRATION_TTL) {
            throw new CloudflareKVLimitExceededException(sprintf(
                'Cloudflare KV expiration_ttl must be at least %d seconds; got %d.',
                self::MIN_EXPIRATION_TTL,
                $ttl,
            ));
        }
    }

    private static function elapsedMs(int $started): float
    {
        return round((hrtime(true) - $started) / 1_000_000, 3);
    }
}
