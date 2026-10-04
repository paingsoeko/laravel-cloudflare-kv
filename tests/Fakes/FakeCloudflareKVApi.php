<?php

declare(strict_types=1);

namespace Kopaing\CloudflareKV\Tests\Fakes;

use Closure;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use stdClass;

/**
 * Test double: an in-memory imitation of the Cloudflare Workers KV REST API,
 * following the documented request and response formats. Used only through Http::fake().
 */
final class FakeCloudflareKVApi
{
    /** @var array<string, array{value: string, expires: int|null}> */
    public array $entries = [];

    /** @var list<array{method: string, operation: string, url: string, body: string}> */
    public array $requests = [];

    /** @var list<PromiseInterface|Closure(Request): PromiseInterface> */
    private array $queuedResponses = [];

    /** @var list<string> */
    private array $bulkFailures = [];

    private int $bulkFailureRounds = 0;

    public function __construct(
        private readonly string $accountId,
        private readonly string $namespaceId,
        private readonly string $token,
    ) {
    }

    /**
     * Answer the next request(s) with these responses before falling back to normal behaviour.
     */
    public function queue(PromiseInterface|Closure ...$responses): self
    {
        array_push($this->queuedResponses, ...$responses);

        return $this;
    }

    /**
     * Report these keys as "unsuccessful" in the next $rounds bulk write/delete responses.
     *
     * @param  list<string>  $keys
     */
    public function failBulkKeys(array $keys, int $rounds = 1): self
    {
        $this->bulkFailures = $keys;
        $this->bulkFailureRounds = $rounds;

        return $this;
    }

    /**
     * A transport-level failure (DNS, connect or read timeout) for queue().
     *
     * @return Closure(Request): PromiseInterface
     */
    public static function connectionFailure(string $message = 'cURL error 28: Operation timed out'): Closure
    {
        return static fn (Request $request): PromiseInterface => Create::rejectionFor(
            new ConnectException($message, $request->toPsrRequest()),
        );
    }

    public function seed(string $key, string $value, ?int $expires = null): void
    {
        $this->entries[$key] = ['value' => $value, 'expires' => $expires];
    }

    public function count(?string $operation = null): int
    {
        return count(array_filter(
            $this->requests,
            static fn (array $request): bool => $operation === null || $request['operation'] === $operation,
        ));
    }

    /**
     * @return list<array{method: string, operation: string, url: string, body: string}>
     */
    public function requestsFor(string $operation): array
    {
        return array_values(array_filter(
            $this->requests,
            static fn (array $request): bool => $request['operation'] === $operation,
        ));
    }

    public function has(string $key): bool
    {
        $this->purgeExpired();

        return isset($this->entries[$key]);
    }

    public function handle(Request $request): PromiseInterface
    {
        $base = sprintf('https://api.cloudflare.com/client/v4/accounts/%s/storage/kv/namespaces/%s', $this->accountId, $this->namespaceId);
        $url = $request->url();
        $path = (string) parse_url($url, PHP_URL_PATH);
        $basePath = (string) parse_url($base, PHP_URL_PATH);
        $operation = $this->operationFor($request->method(), substr($path, strlen($basePath)));

        $this->requests[] = ['method' => $request->method(), 'operation' => $operation, 'url' => $url, 'body' => $request->body()];

        if ($this->queuedResponses !== []) {
            $next = array_shift($this->queuedResponses);

            return $next instanceof Closure ? $next($request) : $next;
        }

        if ($request->header('Authorization') !== ['Bearer '.$this->token]) {
            return $this->error(401, 10000, 'Authentication error');
        }

        if (! str_starts_with($path, $basePath)) {
            return $this->error(404, 10013, 'namespace not found');
        }

        $this->purgeExpired();
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $rest = substr($path, strlen($basePath));

        return match ($operation) {
            'get' => $this->getValue(rawurldecode(substr($rest, 8))),
            'put' => $this->putValue(rawurldecode(substr($rest, 8)), $request->body(), $query),
            'delete' => $this->deleteValue(rawurldecode(substr($rest, 8))),
            'bulk_get' => $this->bulkGet($request->body()),
            'bulk_put' => $this->bulkPut($request->body()),
            'bulk_delete' => $this->bulkDelete($request->body()),
            'list_keys' => $this->listKeys($query),
            default => $this->error(404, 7003, 'Could not route'),
        };
    }

    private function operationFor(string $method, string $rest): string
    {
        return match (true) {
            str_starts_with($rest, '/values/') && $method === 'GET' => 'get',
            str_starts_with($rest, '/values/') && $method === 'PUT' => 'put',
            str_starts_with($rest, '/values/') && $method === 'DELETE' => 'delete',
            $rest === '/bulk/get' && $method === 'POST' => 'bulk_get',
            $rest === '/bulk' && $method === 'PUT' => 'bulk_put',
            $rest === '/bulk/delete' && $method === 'POST' => 'bulk_delete',
            $rest === '/keys' && $method === 'GET' => 'list_keys',
            default => 'unknown',
        };
    }

    private function getValue(string $key): PromiseInterface
    {
        if (! isset($this->entries[$key])) {
            return $this->error(404, 10009, "get: 'key not found'");
        }

        return Http::response($this->entries[$key]['value'], 200, ['Content-Type' => 'application/octet-stream']);
    }

    /**
     * @param  array<mixed>  $query
     */
    private function putValue(string $key, string $body, array $query): PromiseInterface
    {
        $ttl = isset($query['expiration_ttl']) ? (int) $query['expiration_ttl'] : null;

        if ($ttl !== null && $ttl < 60) {
            return $this->error(400, 10014, 'Invalid expiration_ttl of '.$ttl.'. Expiration TTL must be at least 60.');
        }

        $this->entries[$key] = ['value' => $body, 'expires' => $ttl === null ? null : Carbon::now()->getTimestamp() + $ttl];

        return $this->success([]);
    }

    private function deleteValue(string $key): PromiseInterface
    {
        unset($this->entries[$key]);

        return $this->success([]);
    }

    private function bulkGet(string $body): PromiseInterface
    {
        $payload = json_decode($body, true);

        if (! is_array($payload) || ! is_array($payload['keys'] ?? null) || count($payload['keys']) > 100) {
            return $this->error(400, 10001, 'Invalid bulk get request');
        }

        $values = [];

        foreach ($payload['keys'] as $key) {
            $values[$key] = $this->entries[$key]['value'] ?? null;
        }

        return $this->success(['values' => $values === [] ? new stdClass() : $values]);
    }

    private function bulkPut(string $body): PromiseInterface
    {
        $items = json_decode($body, true);

        if (! is_array($items) || ! array_is_list($items) || count($items) > 10_000) {
            return $this->error(400, 10001, 'Invalid bulk write request');
        }

        $failed = $this->takeBulkFailures();
        $written = 0;

        foreach ($items as $item) {
            if (in_array($item['key'], $failed, true)) {
                continue;
            }

            $ttl = $item['expiration_ttl'] ?? null;

            if ($ttl !== null && $ttl < 60) {
                return $this->error(400, 10014, 'Expiration TTL must be at least 60.');
            }

            $this->entries[$item['key']] = [
                'value' => ($item['base64'] ?? false) ? (string) base64_decode($item['value']) : $item['value'],
                'expires' => $ttl === null ? null : Carbon::now()->getTimestamp() + $ttl,
            ];
            $written++;
        }

        return $this->success([
            'successful_key_count' => $written,
            'unsuccessful_keys' => array_values(array_intersect($failed, array_column($items, 'key'))),
        ]);
    }

    private function bulkDelete(string $body): PromiseInterface
    {
        $keys = json_decode($body, true);

        if (! is_array($keys) || ! array_is_list($keys) || count($keys) > 10_000) {
            return $this->error(400, 10001, 'Invalid bulk delete request');
        }

        $failed = $this->takeBulkFailures();
        $deleted = 0;

        foreach ($keys as $key) {
            if (! in_array($key, $failed, true)) {
                unset($this->entries[$key]);
                $deleted++;
            }
        }

        return $this->success([
            'successful_key_count' => $deleted,
            'unsuccessful_keys' => array_values(array_intersect($failed, $keys)),
        ]);
    }

    /**
     * @param  array<mixed>  $query
     */
    private function listKeys(array $query): PromiseInterface
    {
        $limit = (int) ($query['limit'] ?? 1000);

        if ($limit < 10 || $limit > 1000) {
            return $this->error(400, 10001, 'limit must be between 10 and 1000');
        }

        $prefix = (string) ($query['prefix'] ?? '');
        $names = array_values(array_filter(
            array_map('strval', array_keys($this->entries)),
            static fn (string $name): bool => str_starts_with($name, $prefix),
        ));
        sort($names, SORT_STRING);

        // Cursor = the last key name of the previous page, as in a lexicographic scan.
        $after = isset($query['cursor']) ? base64_decode((string) $query['cursor']) : null;

        if ($after !== null) {
            $names = array_values(array_filter($names, static fn (string $name): bool => strcmp($name, $after) > 0));
        }

        $page = array_slice($names, 0, $limit);
        $more = count($names) > $limit;

        return $this->success(
            array_map(fn (string $name): array => array_filter([
                'name' => $name,
                'expiration' => $this->entries[$name]['expires'],
            ], static fn (mixed $value): bool => $value !== null), $page),
            ['count' => count($page), 'cursor' => $more ? base64_encode((string) end($page)) : ''],
        );
    }

    /**
     * @return list<string>
     */
    private function takeBulkFailures(): array
    {
        if ($this->bulkFailureRounds <= 0) {
            return [];
        }

        $this->bulkFailureRounds--;

        return $this->bulkFailures;
    }

    private function purgeExpired(): void
    {
        $now = Carbon::now()->getTimestamp();

        foreach ($this->entries as $key => $entry) {
            if ($entry['expires'] !== null && $entry['expires'] <= $now) {
                unset($this->entries[$key]);
            }
        }
    }

    /**
     * @param  array<mixed>|stdClass  $result
     * @param  array<string, mixed>|null  $resultInfo
     */
    private function success(array|stdClass $result, ?array $resultInfo = null): PromiseInterface
    {
        $body = ['success' => true, 'errors' => [], 'messages' => [], 'result' => $result];

        if ($resultInfo !== null) {
            $body['result_info'] = $resultInfo;
        }

        return Http::response($body, 200);
    }

    private function error(int $status, int $code, string $message): PromiseInterface
    {
        return Http::response([
            'success' => false,
            'errors' => [['code' => $code, 'message' => $message]],
            'messages' => [],
            'result' => null,
        ], $status);
    }
}
