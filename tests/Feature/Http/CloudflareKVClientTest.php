<?php

declare(strict_types=1);

namespace Kopaing\CloudflareKV\Tests\Feature\Http;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Kopaing\CloudflareKV\CloudflareKVClient;
use Kopaing\CloudflareKV\Events\CloudflareKVRequestCompleted;
use Kopaing\CloudflareKV\Events\CloudflareKVRequestFailed;
use Kopaing\CloudflareKV\Events\CloudflareKVRequestRetrying;
use Kopaing\CloudflareKV\Exceptions\CloudflareKVAuthenticationException;
use Kopaing\CloudflareKV\Exceptions\CloudflareKVConnectionException;
use Kopaing\CloudflareKV\Exceptions\CloudflareKVException;
use Kopaing\CloudflareKV\Exceptions\CloudflareKVLimitExceededException;
use Kopaing\CloudflareKV\Exceptions\CloudflareKVRateLimitException;
use Kopaing\CloudflareKV\Exceptions\CloudflareKVRequestException;
use Kopaing\CloudflareKV\Support\RetryPolicy;
use Kopaing\CloudflareKV\Tests\Fakes\FakeCloudflareKVApi;
use Kopaing\CloudflareKV\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class CloudflareKVClientTest extends TestCase
{
    private const BASE = 'https://api.cloudflare.com/client/v4/accounts/'.self::ACCOUNT_ID.'/storage/kv/namespaces/'.self::NAMESPACE_ID;

    private FakeCloudflareKVApi $api;

    protected function setUp(): void
    {
        parent::setUp();

        $this->api = $this->fakeApi();
    }

    private function client(int $attempts = 3, int $maxDelayMs = 2000, bool $events = false): CloudflareKVClient
    {
        return new CloudflareKVClient(
            http: $this->app->make(Factory::class),
            accountId: self::ACCOUNT_ID,
            namespaceId: self::NAMESPACE_ID,
            apiToken: self::API_TOKEN,
            retry: new RetryPolicy($attempts, 100, $maxDelayMs, jitter: false),
            events: $events ? $this->app->make('events') : null,
        );
    }

    #[Test]
    public function get_returns_the_raw_body_and_url_encodes_the_key(): void
    {
        $this->api->seed('app:a/b c%d', '{"raw":true}');

        $this->assertSame('{"raw":true}', $this->client()->get('app:a/b c%d'));

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === self::BASE.'/values/app%3Aa%2Fb%20c%25d'
            && $request->header('Authorization') === ['Bearer '.self::API_TOKEN]);
    }

    #[Test]
    public function get_returns_null_for_a_missing_key(): void
    {
        $this->assertNull($this->client()->get('missing'));
        $this->assertSame(1, $this->api->count('get'));
    }

    #[Test]
    public function put_sends_a_raw_body_with_expiration_ttl(): void
    {
        $this->client()->put('app:k', 'payload', 120);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
            && $request->url() === self::BASE.'/values/app%3Ak?expiration_ttl=120'
            && $request->body() === 'payload'
            && $request->header('Content-Type') === ['application/octet-stream']);

        $this->assertSame('payload', $this->api->entries['app:k']['value']);
    }

    #[Test]
    public function put_without_ttl_never_expires(): void
    {
        $this->client()->put('app:k', 'payload');

        $this->assertNull($this->api->entries['app:k']['expires']);
        Http::assertSent(fn (Request $request): bool => ! str_contains($request->url(), 'expiration'));
    }

    #[Test]
    public function documented_limits_are_enforced_before_any_request(): void
    {
        $client = $this->client();

        foreach ([
            fn () => $client->put('k', 'v', 59),
            fn () => $client->put(str_repeat('k', 513), 'v'),
            fn () => $client->put('k', str_repeat('v', CloudflareKVClient::MAX_VALUE_BYTES + 1)),
            fn () => $client->get(''),
            fn () => $client->putMany(['k' => 'v'], 30),
        ] as $call) {
            try {
                $call();
                $this->fail('Expected a limit exception.');
            } catch (CloudflareKVLimitExceededException) {
                // expected
            }
        }

        $this->assertSame(0, $this->api->count());
    }

    #[Test]
    public function delete_succeeds_and_tolerates_missing_keys(): void
    {
        $this->api->seed('app:k', 'v');
        $client = $this->client();

        $client->delete('app:k');
        $this->api->queue(Http::response(['success' => false, 'errors' => [['code' => 10009, 'message' => 'key not found']]], 404));
        $client->delete('app:k');

        $this->assertFalse($this->api->has('app:k'));
        $this->assertSame(2, $this->api->count('delete'));
    }

    #[Test]
    public function authentication_errors_are_mapped_and_never_echo_the_token(): void
    {
        $this->api->queue(Http::response([
            'success' => false,
            'errors' => [['code' => 10000, 'message' => 'Authentication error for '.self::API_TOKEN]],
        ], 403));

        try {
            $this->client()->get('k');
            $this->fail('Expected an authentication exception.');
        } catch (CloudflareKVAuthenticationException $e) {
            $this->assertSame(403, $e->status);
            $this->assertSame([10000], $e->errorCodes());
            $this->assertStringContainsString('[10000]', $e->getMessage());
            $this->assertStringNotContainsString(self::API_TOKEN, $e->getMessage());
        }

        $this->assertSame(1, $this->api->count(), 'Authentication failures must not be retried.');
    }

    #[Test]
    public function a_wrong_token_yields_401(): void
    {
        $client = new CloudflareKVClient($this->app->make(Factory::class), self::ACCOUNT_ID, self::NAMESPACE_ID, 'wrong-token');

        $this->expectException(CloudflareKVAuthenticationException::class);

        $client->get('k');
    }

    #[Test]
    public function rate_limits_honour_a_short_retry_after(): void
    {
        $this->api->seed('k', 'v');
        $this->api->queue(Http::response(['success' => false, 'errors' => [['code' => 10429, 'message' => 'Too many requests']]], 429, ['Retry-After' => '1']));

        $this->assertSame('v', $this->client()->get('k'));
        $this->assertSame(2, $this->api->count());
        Sleep::assertSlept(fn ($duration): bool => (int) $duration->totalMilliseconds === 1000, 1);
    }

    #[Test]
    public function rate_limits_with_a_long_retry_after_fail_fast(): void
    {
        $this->api->queue(Http::response(['success' => false, 'errors' => []], 429, ['Retry-After' => '300']));

        try {
            $this->client()->get('k');
            $this->fail('Expected a rate limit exception.');
        } catch (CloudflareKVRateLimitException $e) {
            $this->assertSame(300, $e->retryAfter);
            $this->assertSame(429, $e->status);
        }

        $this->assertSame(1, $this->api->count());
        Sleep::assertNeverSlept();
    }

    #[Test]
    public function rate_limits_exhaust_the_retry_budget(): void
    {
        $limited = Http::response(['success' => false, 'errors' => []], 429);
        $this->api->queue($limited, $limited, $limited);

        $this->expectException(CloudflareKVRateLimitException::class);

        try {
            $this->client(attempts: 3)->put('k', 'v');
        } finally {
            $this->assertSame(3, $this->api->count());
            Sleep::assertSleptTimes(2);
        }
    }

    #[Test]
    public function server_errors_are_retried_with_exponential_backoff(): void
    {
        $this->api->seed('k', 'v');
        $this->api->queue(Http::response('upstream error', 502), Http::response(['success' => false], 503));

        $this->assertSame('v', $this->client()->get('k'));

        $this->assertSame(3, $this->api->count());
        Sleep::assertSequence([Sleep::usleep(100_000), Sleep::usleep(200_000)]);
    }

    #[Test]
    public function persistent_server_errors_raise_a_request_exception(): void
    {
        $error = Http::response(['success' => false, 'errors' => [['code' => 10001, 'message' => 'Internal error']]], 500);
        $this->api->queue($error, $error);

        try {
            $this->client(attempts: 2)->get('k');
            $this->fail('Expected a request exception.');
        } catch (CloudflareKVRequestException $e) {
            $this->assertSame(500, $e->status);
            $this->assertSame('get', $e->operation);
            $this->assertNotInstanceOf(CloudflareKVAuthenticationException::class, $e);
        }
    }

    #[Test]
    public function client_errors_are_not_retried(): void
    {
        $this->api->queue(Http::response(['success' => false, 'errors' => [['code' => 10014, 'message' => 'bad ttl']]], 400));

        $this->expectException(CloudflareKVRequestException::class);
        $this->expectExceptionMessage('[10014] bad ttl');

        try {
            $this->client()->put('k', 'v', 60);
        } finally {
            $this->assertSame(1, $this->api->count());
        }
    }

    #[Test]
    public function timeouts_are_retried_then_reported_as_connection_errors(): void
    {
        $this->api->queue(FakeCloudflareKVApi::connectionFailure(), FakeCloudflareKVApi::connectionFailure(), FakeCloudflareKVApi::connectionFailure());

        try {
            $this->client()->get('k');
            $this->fail('Expected a connection exception.');
        } catch (CloudflareKVConnectionException $e) {
            $this->assertStringContainsString('timed out', $e->getMessage());
            $this->assertStringNotContainsString(self::API_TOKEN, $e->getMessage());
            $this->assertNull($e->getPrevious(), 'The transport exception carries the Authorization header.');
        }

        $this->assertSame(3, $this->api->count());
    }

    #[Test]
    public function a_transient_timeout_recovers(): void
    {
        $this->api->seed('k', 'v');
        $this->api->queue(FakeCloudflareKVApi::connectionFailure());

        $this->assertSame('v', $this->client()->get('k'));
    }

    #[Test]
    public function invalid_json_bodies_are_rejected(): void
    {
        $this->api->queue(Http::response('<html>gateway</html>', 200));

        $this->expectException(CloudflareKVRequestException::class);
        $this->expectExceptionMessage('body is not JSON');

        $this->client()->put('k', 'v');
    }

    #[Test]
    public function success_false_payloads_are_rejected_even_with_http_200(): void
    {
        $this->api->queue(Http::response(['success' => false, 'errors' => [['code' => 10999, 'message' => 'odd']]], 200));

        $this->expectException(CloudflareKVRequestException::class);
        $this->expectExceptionMessage('[10999] odd');

        $this->client()->put('k', 'v');
    }

    #[Test]
    public function bulk_get_chunks_by_100_keys_and_maps_missing_keys_to_null(): void
    {
        $keys = array_map(fn (int $i): string => 'k'.$i, range(1, 250));
        $this->api->seed('k1', 'one');
        $this->api->seed('k250', 'last');

        $values = $this->client()->getMany($keys);

        $this->assertCount(250, $values);
        $this->assertSame('one', $values['k1']);
        $this->assertSame('last', $values['k250']);
        $this->assertNull($values['k2']);
        $this->assertSame(3, $this->api->count('bulk_get'));

        $first = json_decode($this->api->requestsFor('bulk_get')[0]['body'], true);
        $this->assertCount(100, $first['keys']);
        $this->assertSame('text', $first['type']);
    }

    #[Test]
    public function bulk_get_handles_numeric_key_names(): void
    {
        $this->api->seed('123', 'numeric');

        $this->assertSame(['123' => 'numeric', '456' => null], $this->client()->getMany(['123', '456']));
    }

    #[Test]
    public function bulk_get_falls_back_to_single_reads_when_the_response_is_too_large(): void
    {
        $this->api->seed('a', '1');
        $this->api->seed('b', '2');
        $this->api->queue(Http::response(['success' => false, 'errors' => [['code' => 10413, 'message' => 'too large']]], 413));

        $this->assertSame(['a' => '1', 'b' => '2'], $this->client()->getMany(['a', 'b']));
        $this->assertSame(2, $this->api->count('get'));
    }

    #[Test]
    public function bulk_get_rejects_non_text_values(): void
    {
        $this->api->queue(Http::response(['success' => true, 'result' => ['values' => ['a' => ['decoded' => 'json']]]]));

        $this->expectException(CloudflareKVRequestException::class);

        $this->client()->getMany(['a']);
    }

    #[Test]
    public function bulk_put_sends_documented_items_and_chunks_at_10000_pairs(): void
    {
        $values = [];
        for ($i = 0; $i < 10_001; $i++) {
            $values['k'.$i] = 'v'.$i;
        }

        $this->assertSame([], $this->client()->putMany($values, 300));

        $requests = $this->api->requestsFor('bulk_put');
        $this->assertCount(2, $requests);
        $this->assertSame(self::BASE.'/bulk', $requests[0]['url']);

        $items = json_decode($requests[0]['body'], true);
        $this->assertCount(10_000, $items);
        $this->assertSame(['key' => 'k0', 'value' => 'v0', 'expiration_ttl' => 300], $items[0]);
        $this->assertCount(1, json_decode($requests[1]['body'], true));
        $this->assertCount(10_001, $this->api->entries);
    }

    #[Test]
    public function bulk_put_retries_only_the_unsuccessful_keys(): void
    {
        $this->api->failBulkKeys(['b'], rounds: 1);

        $this->assertSame([], $this->client()->putMany(['a' => '1', 'b' => '2', 'c' => '3']));

        $requests = $this->api->requestsFor('bulk_put');
        $this->assertCount(2, $requests);
        $this->assertSame([['key' => 'b', 'value' => '2']], json_decode($requests[1]['body'], true));
        $this->assertSame('2', $this->api->entries['b']['value']);
    }

    #[Test]
    public function bulk_put_reports_keys_that_keep_failing(): void
    {
        $this->api->failBulkKeys(['b'], rounds: 10);

        $this->assertSame(['b'], $this->client(attempts: 3)->putMany(['a' => '1', 'b' => '2']));
        $this->assertSame(3, $this->api->count('bulk_put'));
    }

    #[Test]
    public function bulk_delete_posts_a_json_list_of_names(): void
    {
        $this->api->seed('a', '1');
        $this->api->seed('b', '2');

        $this->assertSame([], $this->client()->deleteMany(['a', 'b', 'a']));

        $request = $this->api->requestsFor('bulk_delete')[0];
        $this->assertSame(self::BASE.'/bulk/delete', $request['url']);
        $this->assertSame(['a', 'b'], json_decode($request['body'], true));
        $this->assertSame([], $this->api->entries);
    }

    #[Test]
    public function list_keys_paginates_with_cursors(): void
    {
        foreach (range(1, 25) as $i) {
            $this->api->seed(sprintf('app:%02d', $i), 'v');
        }
        $this->api->seed('other:1', 'v');

        $client = $this->client();
        $first = $client->listKeys('app:', null, 10);
        $second = $client->listKeys('app:', $first['cursor'], 10);
        $third = $client->listKeys('app:', $second['cursor'], 10);

        $this->assertSame('app:01', $first['keys'][0]);
        $this->assertCount(10, $second['keys']);
        $this->assertSame(['app:21', 'app:22', 'app:23', 'app:24', 'app:25'], $third['keys']);
        $this->assertNull($third['cursor']);

        $url = $this->api->requestsFor('list_keys')[1]['url'];
        $this->assertStringContainsString('prefix=app%3A', $url);
        $this->assertStringContainsString('limit=10', $url);
        $this->assertStringContainsString('cursor=', $url);
    }

    #[Test]
    public function list_limits_are_clamped_to_the_documented_range(): void
    {
        $this->client()->listKeys('p:', null, 5000);
        $this->client()->listKeys('p:', null, 1);

        $this->assertStringContainsString('limit=1000', $this->api->requestsFor('list_keys')[0]['url']);
        $this->assertStringContainsString('limit=10', $this->api->requestsFor('list_keys')[1]['url']);
    }

    #[Test]
    public function instrumentation_events_carry_metrics_but_no_keys_or_values(): void
    {
        Event::fake([CloudflareKVRequestCompleted::class, CloudflareKVRequestRetrying::class, CloudflareKVRequestFailed::class]);
        $this->api->seed('secret-key', 'secret-value');
        $this->api->queue(Http::response('', 503));

        $client = $this->client(events: true);
        $client->get('secret-key');

        Event::assertDispatched(CloudflareKVRequestRetrying::class, fn (CloudflareKVRequestRetrying $e): bool => $e->operation === 'get' && $e->reason === 'http_503' && $e->attempt === 1);
        Event::assertDispatched(CloudflareKVRequestCompleted::class, fn (CloudflareKVRequestCompleted $e): bool => $e->attempts === 2 && $e->status === 200 && $e->durationMs >= 0);

        $this->api->queue(Http::response('', 400));

        try {
            $client->put('secret-key', 'secret-value');
        } catch (CloudflareKVException) {
            // expected
        }

        Event::assertDispatched(CloudflareKVRequestFailed::class, fn (CloudflareKVRequestFailed $e): bool => $e->operation === 'put' && $e->status === 400 && $e->exception === CloudflareKVRequestException::class);

        foreach (Event::dispatched(CloudflareKVRequestCompleted::class) as [$event]) {
            $this->assertStringNotContainsString('secret', serialize($event));
        }
    }

    #[Test]
    public function debug_output_redacts_the_token(): void
    {
        $this->assertStringNotContainsString(self::API_TOKEN, print_r($this->client()->__debugInfo(), true));
    }
}
