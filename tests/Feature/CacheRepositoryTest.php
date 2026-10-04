<?php

declare(strict_types=1);

namespace Kopaing\CloudflareKV\Tests\Feature;

use ArrayObject;
use BadMethodCallException;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Kopaing\CloudflareKV\Events\CloudflareKVPayloadRejected;
use Kopaing\CloudflareKV\Exceptions\CloudflareKVConfigurationException;
use Kopaing\CloudflareKV\Exceptions\CloudflareKVSerializationException;
use Kopaing\CloudflareKV\Exceptions\CloudflareKVUnsupportedOperationException;
use Kopaing\CloudflareKV\Support\KeyFormatter;
use Kopaing\CloudflareKV\Support\ValueSerializer;
use Kopaing\CloudflareKV\Tests\Fakes\FakeCloudflareKVApi;
use Kopaing\CloudflareKV\Tests\Fixtures\Product;
use Kopaing\CloudflareKV\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class CacheRepositoryTest extends TestCase
{
    private FakeCloudflareKVApi $api;

    protected function setUp(): void
    {
        parent::setUp();

        $this->api = $this->fakeApi();
        Carbon::setTestNow('2026-10-04 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function put_get_has_and_forget(): void
    {
        $cache = Cache::store('cloudflare');

        $this->assertTrue($cache->put('user:1', ['name' => 'Ada'], 3600));
        $this->assertSame(['name' => 'Ada'], $cache->get('user:1'));
        $this->assertTrue($cache->has('user:1'));
        $this->assertSame(Carbon::now()->getTimestamp() + 3600, $this->api->entries['test:user:1']['expires']);

        $this->assertTrue($cache->forget('user:1'));
        $this->assertNull($cache->get('user:1'));
        $this->assertFalse($cache->has('user:1'));
        $this->assertSame('fallback', $cache->get('user:1', 'fallback'));
    }

    #[Test]
    public function the_readme_example_works(): void
    {
        Cache::store('cloudflare')->put('tracking:ORDER-1001', [
            'status' => 'shipped',
            'updated_at' => now()->toISOString(),
        ], 86400);

        $this->assertSame('shipped', Cache::store('cloudflare')->get('tracking:ORDER-1001')['status']);
    }

    #[Test]
    public function objects_round_trip_with_the_php_serializer(): void
    {
        Cache::store('cloudflare')->put('obj', new ArrayObject(['a' => 1]), 600);

        $this->assertEquals(new ArrayObject(['a' => 1]), Cache::store('cloudflare')->get('obj'));
    }

    #[Test]
    public function eloquent_collections_round_trip_with_laravel_13s_default_serializable_classes(): void
    {
        // New Laravel 13 apps ship with 'serializable_classes' => false in config/cache.php.
        $this->app['config']->set('cache.serializable_classes', false);
        $this->app->make('cache')->forgetDriver('cloudflare');

        $products = new EloquentCollection([
            new Product(['id' => 1, 'name' => 'Lamp']),
            new Product(['id' => 2, 'name' => 'Desk']),
        ]);

        $cached = Cache::store('cloudflare')->remember('products:featured', 3600, fn () => $products);
        $fromKv = Cache::store('cloudflare')->get('products:featured');

        $this->assertInstanceOf(EloquentCollection::class, $fromKv);
        $this->assertInstanceOf(Product::class, $fromKv->first());
        $this->assertSame(['Lamp', 'Desk'], $fromKv->pluck('name')->all());
        $this->assertEquals($cached, $fromKv);
    }

    #[Test]
    public function remember_only_runs_the_callback_on_a_miss(): void
    {
        $calls = 0;
        $callback = function () use (&$calls): array {
            $calls++;

            return ['featured' => [1, 2, 3]];
        };

        $first = Cache::store('cloudflare')->remember('products:featured', 3600, $callback);
        $second = Cache::store('cloudflare')->remember('products:featured', 3600, $callback);

        $this->assertSame($first, $second);
        $this->assertSame(1, $calls);
    }

    #[Test]
    public function falsy_values_are_cache_hits(): void
    {
        $cache = Cache::store('cloudflare');
        $cache->put('false', false, 60);
        $cache->put('zero', 0, 60);
        $cache->put('empty', '', 60);

        $this->assertFalse($cache->get('false', 'default'));
        $this->assertSame(0, $cache->get('zero', 'default'));
        $this->assertSame('', $cache->get('empty', 'default'));
    }

    #[Test]
    public function forever_writes_without_a_cloudflare_ttl(): void
    {
        Cache::store('cloudflare')->forever('app:settings', ['theme' => 'dark']);

        $this->assertNull($this->api->entries['test:app:settings']['expires']);
        $this->assertSame(['theme' => 'dark'], Cache::store('cloudflare')->get('app:settings'));
        $this->assertStringNotContainsString('expiration_ttl', $this->api->requestsFor('put')[0]['url']);
    }

    #[Test]
    public function forever_ttl_caps_forever_writes_when_configured(): void
    {
        $this->configureStore(['forever_ttl' => 604800]);

        Cache::store('cloudflare')->forever('k', 'v');

        $this->assertSame(Carbon::now()->getTimestamp() + 604800, $this->api->entries['test:k']['expires']);
    }

    #[Test]
    public function short_ttls_are_enforced_logically_while_kv_keeps_the_minimum(): void
    {
        $cache = Cache::store('cloudflare');
        $cache->put('short', 'value', 10);

        $this->assertStringContainsString('expiration_ttl=60', $this->api->requestsFor('put')[0]['url']);

        Carbon::setTestNow(Carbon::now()->addSeconds(9));
        $this->assertSame('value', $cache->get('short'));

        Carbon::setTestNow(Carbon::now()->addSecond());
        $this->assertTrue($this->api->has('test:short'), 'KV still retains the entry...');
        $this->assertNull($cache->get('short'), '...but it is never returned once expired.');
        $this->assertSame(['short' => null], $cache->many(['short']));
    }

    #[Test]
    public function ttls_at_or_above_the_minimum_are_passed_through_exactly(): void
    {
        Cache::store('cloudflare')->put('a', 1, 60);
        Cache::store('cloudflare')->put('b', 1, now()->addMinutes(5));

        $this->assertStringContainsString('expiration_ttl=60', $this->api->requestsFor('put')[0]['url']);
        $this->assertStringContainsString('expiration_ttl=300', $this->api->requestsFor('put')[1]['url']);
    }

    #[Test]
    public function zero_or_negative_ttls_forget_the_key(): void
    {
        $this->api->seed('test:k', 'x');

        Cache::store('cloudflare')->put('k', 'v', 0);

        $this->assertFalse($this->api->has('test:k'));
        $this->assertSame(0, $this->api->count('put'));
    }

    #[Test]
    public function many_and_put_many_use_bulk_endpoints(): void
    {
        $cache = Cache::store('cloudflare');

        $this->assertTrue($cache->putMany(['user:1' => 'Ada', 'user:2' => 'Linus'], 3600));
        $this->assertSame(1, $this->api->count('bulk_put'));
        $this->assertSame(0, $this->api->count('put'));

        $this->assertSame(
            ['user:1' => 'Ada', 'user:2' => 'Linus', 'user:3' => null],
            $cache->many(['user:1', 'user:2', 'user:3']),
        );
        $this->assertSame(['user:1' => 'Ada', 'user:9' => 'default'], $cache->many(['user:1', 'user:9' => 'default']));
        $this->assertSame(2, $this->api->count('bulk_get'));
        $this->assertSame(0, $this->api->count('get'));
    }

    #[Test]
    public function put_many_with_one_item_uses_a_single_put(): void
    {
        Cache::store('cloudflare')->putMany(['only' => 1], 120);

        $this->assertSame(1, $this->api->count('put'));
        $this->assertSame(0, $this->api->count('bulk_put'));
    }

    #[Test]
    public function put_many_reports_partial_failure(): void
    {
        $this->configureStore(['retry' => ['times' => 1]]);
        $this->api->failBulkKeys(['test:b'], rounds: 5);

        $this->assertFalse(Cache::store('cloudflare')->putMany(['a' => 1, 'b' => 2], 120));
        $this->assertSame(1, Cache::store('cloudflare')->get('a'));
    }

    #[Test]
    public function many_can_fall_back_to_single_reads(): void
    {
        $this->configureStore(['bulk_get' => false]);
        Cache::store('cloudflare')->put('a', 1, 60);

        $this->assertSame(['a' => 1, 'b' => null], Cache::store('cloudflare')->many(['a', 'b']));
        $this->assertSame(0, $this->api->count('bulk_get'));
        $this->assertSame(2, $this->api->count('get'));
    }

    #[Test]
    public function keys_that_are_not_valid_kv_names_are_hashed_transparently(): void
    {
        $cache = Cache::store('cloudflare');
        $cache->put('key with spaces', 'v', 60);

        $this->assertSame('v', $cache->get('key with spaces'));
        $this->assertArrayHasKey('test:'.KeyFormatter::HASH_MARKER.hash('sha256', 'key with spaces'), $this->api->entries);
    }

    #[Test]
    public function increment_and_decrement_are_rejected_by_default(): void
    {
        $cache = Cache::store('cloudflare');

        foreach ([fn () => $cache->increment('visits'), fn () => $cache->decrement('visits'), fn () => $cache->touch('visits', 60)] as $call) {
            try {
                $call();
                $this->fail('Expected an unsupported operation exception.');
            } catch (CloudflareKVUnsupportedOperationException $e) {
                $this->assertStringContainsString('allow_non_atomic_updates', $e->getMessage());
            }
        }

        $this->assertSame(0, $this->api->count(), 'Rejected operations must not touch KV.');
    }

    #[Test]
    public function non_atomic_increments_work_when_explicitly_enabled(): void
    {
        $this->configureStore(['allow_non_atomic_updates' => true]);
        $cache = Cache::store('cloudflare');

        $this->assertSame(1, $cache->increment('visits'));
        $this->assertSame(6, $cache->increment('visits', 5));
        $this->assertSame(4, $cache->decrement('visits', 2));
        $this->assertSame(4, $cache->get('visits'));
        $this->assertNull($this->api->entries['test:visits']['expires']);
    }

    #[Test]
    public function non_atomic_increments_preserve_the_remaining_ttl(): void
    {
        $this->configureStore(['allow_non_atomic_updates' => true]);
        $cache = Cache::store('cloudflare');
        $cache->put('hits', 10, 600);

        Carbon::setTestNow(Carbon::now()->addSeconds(100));
        $this->assertSame(11, $cache->increment('hits'));

        $this->assertStringContainsString('expiration_ttl=500', $this->api->requestsFor('put')[1]['url']);

        Carbon::setTestNow(Carbon::now()->addSeconds(500));
        $this->assertNull($cache->get('hits'));
    }

    #[Test]
    public function incrementing_a_non_numeric_value_returns_false(): void
    {
        $this->configureStore(['allow_non_atomic_updates' => true]);
        Cache::store('cloudflare')->put('name', 'Ada', 60);

        $this->assertFalse(Cache::store('cloudflare')->increment('name'));
        $this->assertSame('Ada', Cache::store('cloudflare')->get('name'));
    }

    #[Test]
    public function touch_extends_expiration_when_non_atomic_updates_are_enabled(): void
    {
        $this->configureStore(['allow_non_atomic_updates' => true]);
        $cache = Cache::store('cloudflare');
        $cache->put('session', 'data', 60);

        $this->assertTrue($cache->touch('session', 3600));

        Carbon::setTestNow(Carbon::now()->addSeconds(120));
        $this->assertSame('data', $cache->get('session'));
        $this->assertFalse($cache->touch('missing', 60));
    }

    #[Test]
    public function add_uses_laravels_non_atomic_fallback(): void
    {
        $cache = Cache::store('cloudflare');

        $this->assertTrue($cache->add('once', 'first', 60));
        $this->assertFalse($cache->add('once', 'second', 60));
        $this->assertSame('first', $cache->get('once'));
    }

    #[Test]
    public function pull_reads_then_forgets(): void
    {
        Cache::store('cloudflare')->put('k', 'v', 60);

        $this->assertSame('v', Cache::store('cloudflare')->pull('k'));
        $this->assertNull(Cache::store('cloudflare')->get('k'));
    }

    #[Test]
    public function flush_deletes_only_this_stores_prefix_across_pages(): void
    {
        for ($i = 0; $i < 2500; $i++) {
            $this->api->seed('test:item:'.$i, 'v');
        }
        $this->api->seed('test2:keep', 'v');
        $this->api->seed('other-app:keep', 'v');
        $this->api->seed('tes', 'v');

        $this->assertTrue(Cache::store('cloudflare')->flush());

        $this->assertSame(['test2:keep', 'other-app:keep', 'tes'], array_keys($this->api->entries));
        $this->assertSame(3, $this->api->count('list_keys'));
        $this->assertSame(1, $this->api->count('bulk_delete'));
    }

    #[Test]
    public function flush_never_deletes_foreign_keys_even_if_the_listing_returns_them(): void
    {
        $this->api->seed('test:mine', 'v');
        $this->api->seed('other-app:theirs', 'v');
        $this->api->queue(Http::response([
            'success' => true,
            'result' => [['name' => 'test:mine'], ['name' => 'other-app:theirs']],
            'result_info' => ['count' => 2, 'cursor' => ''],
        ]));

        $this->assertTrue(Cache::store('cloudflare')->flush());

        $this->assertSame(['test:mine'], json_decode($this->api->requestsFor('bulk_delete')[0]['body'], true));
        $this->assertTrue($this->api->has('other-app:theirs'));
    }

    #[Test]
    public function flush_stops_if_the_api_repeats_a_cursor(): void
    {
        $page = Http::response([
            'success' => true,
            'result' => [['name' => 'test:a']],
            'result_info' => ['count' => 1, 'cursor' => 'same'],
        ]);
        $this->api->queue($page, $page);
        $this->api->seed('test:a', 'v');

        $this->assertTrue(Cache::store('cloudflare')->flush());
        $this->assertSame(2, $this->api->count('list_keys'));
    }

    #[Test]
    public function flush_deletes_in_batches_of_10000(): void
    {
        for ($i = 0; $i < 10_500; $i++) {
            $this->api->seed(sprintf('test:%05d', $i), 'v');
        }

        $this->assertTrue(Cache::store('cloudflare')->flush());

        $this->assertSame([], $this->api->entries);
        $this->assertSame(2, $this->api->count('bulk_delete'));
        $this->assertCount(10_000, json_decode($this->api->requestsFor('bulk_delete')[0]['body'], true));
    }

    #[Test]
    public function flush_reports_keys_that_could_not_be_deleted(): void
    {
        $this->configureStore(['retry' => ['times' => 1]]);
        $this->api->seed('test:a', 'v');
        $this->api->seed('test:b', 'v');
        $this->api->failBulkKeys(['test:b'], rounds: 5);

        $this->assertFalse(Cache::store('cloudflare')->flush());
    }

    #[Test]
    public function flush_refuses_to_wipe_an_unprefixed_namespace(): void
    {
        $this->configureStore(['prefix' => '']);
        $this->app['config']->set('cache.prefix', '');
        $this->app->make('cache')->forgetDriver('cloudflare');
        $this->api->seed('someone-else', 'v');

        try {
            Cache::store('cloudflare')->flush();
            $this->fail('Expected flush to be refused.');
        } catch (CloudflareKVConfigurationException $e) {
            $this->assertStringContainsString('allow_without_prefix', $e->getMessage());
        }

        $this->assertTrue($this->api->has('someone-else'));
        $this->assertSame(0, $this->api->count());
    }

    #[Test]
    public function flush_without_prefix_requires_explicit_opt_in(): void
    {
        $this->configureStore(['prefix' => '', 'flush' => ['allow_without_prefix' => true]]);
        $this->app['config']->set('cache.prefix', '');
        $this->app->make('cache')->forgetDriver('cloudflare');
        $this->api->seed('anything', 'v');

        $this->assertTrue(Cache::store('cloudflare')->flush());
        $this->assertSame([], $this->api->entries);
    }

    #[Test]
    public function flush_can_be_disabled(): void
    {
        $this->configureStore(['flush' => ['enabled' => false]]);

        $this->expectException(CloudflareKVConfigurationException::class);
        $this->expectExceptionMessage('flush.enabled');

        Cache::store('cloudflare')->flush();
    }

    #[Test]
    public function tags_are_explicitly_unsupported(): void
    {
        $this->assertFalse(Cache::store('cloudflare')->supportsTags());

        $this->expectException(BadMethodCallException::class);

        Cache::store('cloudflare')->tags(['users'])->put('k', 'v', 60);
    }

    #[Test]
    public function locks_are_unsupported_without_a_lock_store(): void
    {
        $this->expectException(CloudflareKVUnsupportedOperationException::class);
        $this->expectExceptionMessage('lock_store');

        Cache::store('cloudflare')->lock('report', 10);
    }

    #[Test]
    public function locks_can_be_delegated_to_a_lock_capable_store(): void
    {
        $this->configureStore(['lock_store' => 'array']);

        $lock = Cache::store('cloudflare')->lock('report', 10);

        $this->assertInstanceOf(Lock::class, $lock);
        $this->assertTrue($lock->get());
        $this->assertFalse(Cache::store('cloudflare')->lock('report', 10)->get());
        $this->assertTrue($lock->release());
        $this->assertSame(0, $this->api->count(), 'Locks must never be stored in KV.');
    }

    #[Test]
    public function flexible_works_when_locks_are_delegated(): void
    {
        $this->configureStore(['lock_store' => 'array']);

        $value = Cache::store('cloudflare')->flexible('stats', [60, 120], fn (): string => 'fresh');

        $this->assertSame('fresh', $value);
        $this->assertSame('fresh', Cache::store('cloudflare')->get('stats'));
    }

    #[Test]
    public function a_store_cannot_lock_on_itself_or_on_another_kv_store(): void
    {
        $this->configureStore(['lock_store' => 'cloudflare']);

        try {
            Cache::store('cloudflare');
            $this->fail('Expected a configuration exception.');
        } catch (CloudflareKVConfigurationException $e) {
            $this->assertStringContainsString('itself', $e->getMessage());
        }

        $this->app['config']->set('cache.stores.kv2', ['driver' => 'cloudflare', 'lock_store' => 'cloudflare'] + $this->app['config']->get('cache.stores.cloudflare'));
        $this->configureStore(['lock_store' => null]);

        $this->expectException(CloudflareKVConfigurationException::class);
        Cache::store('kv2')->lock('x', 1);
    }

    #[Test]
    public function tampered_entries_are_treated_as_misses(): void
    {
        Event::fake([CloudflareKVPayloadRejected::class]);
        Cache::store('cloudflare')->put('k', 'v', 60);

        $envelope = json_decode($this->api->entries['test:k']['value'], true);
        $envelope['d'] = base64_encode(serialize(new ArrayObject(['evil'])));
        $this->api->entries['test:k']['value'] = json_encode($envelope);
        $this->api->seed('test:garbage', 'written by something else');

        $this->assertNull(Cache::store('cloudflare')->get('k'));
        $this->assertNull(Cache::store('cloudflare')->get('garbage'));

        Event::assertDispatched(CloudflareKVPayloadRejected::class, fn ($e): bool => $e->reason === 'signature');
        Event::assertDispatched(CloudflareKVPayloadRejected::class, fn ($e): bool => $e->reason === 'malformed');
    }

    #[Test]
    public function entries_written_with_another_signing_key_are_misses(): void
    {
        $foreign = new ValueSerializer('php', 'another-application-key');
        $this->api->seed('test:k', $foreign->encode('foreign', null, 'test:k'));

        $this->assertNull(Cache::store('cloudflare')->get('k'));
    }

    #[Test]
    public function encryption_keeps_plaintext_out_of_kv(): void
    {
        $this->configureStore(['encrypt' => true]);

        Cache::store('cloudflare')->put('pii', ['email' => 'ada@example.com'], 60);

        $this->assertStringNotContainsString('ada@example.com', $this->api->entries['test:pii']['value']);
        $this->assertSame(['email' => 'ada@example.com'], Cache::store('cloudflare')->get('pii'));
    }

    #[Test]
    public function the_json_serializer_refuses_objects(): void
    {
        $this->configureStore(['serializer' => 'json']);

        $this->expectException(CloudflareKVSerializationException::class);

        Cache::store('cloudflare')->put('obj', new ArrayObject(), 60);
    }

    #[Test]
    public function laravel_cache_events_are_dispatched(): void
    {
        Event::fake([CacheHit::class, CacheMissed::class, KeyWritten::class]);

        Cache::store('cloudflare')->put('k', 'v', 60);
        Cache::store('cloudflare')->get('k');
        Cache::store('cloudflare')->get('missing');

        Event::assertDispatched(KeyWritten::class, fn ($e): bool => $e->key === 'k' && $e->storeName === 'cloudflare');
        Event::assertDispatched(CacheHit::class, fn ($e): bool => $e->key === 'k');
        Event::assertDispatched(CacheMissed::class, fn ($e): bool => $e->key === 'missing');
    }

    #[Test]
    public function it_can_be_the_default_cache_store(): void
    {
        $this->app['config']->set('cache.default', 'cloudflare');

        Cache::put('default-store', 'yes', 60);

        $this->assertSame('yes', Cache::get('default-store'));
        $this->assertArrayHasKey('test:default-store', $this->api->entries);
        $this->assertSame('test:', Cache::getPrefix());
    }
}
