<?php

declare(strict_types=1);

namespace Kopaing\CloudflareKV\Tests\Integration;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Kopaing\CloudflareKV\Exceptions\CloudflareKVAuthenticationException;
use Kopaing\CloudflareKV\Tests\Fixtures\Product;
use Kopaing\CloudflareKV\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Throwable;

/**
 * Talks to a real Cloudflare KV namespace. Skipped unless explicitly enabled:
 *
 *   CLOUDFLARE_KV_INTEGRATION=true \
 *   CLOUDFLARE_ACCOUNT_ID=... CLOUDFLARE_KV_NAMESPACE_ID=... CLOUDFLARE_API_TOKEN=... \
 *   vendor/bin/phpunit --testsuite=Integration
 *
 * Use a dedicated, empty test namespace. Every run writes under a random prefix and
 * flushes only that prefix afterwards.
 */
final class CloudflareKVLiveTest extends TestCase
{
    private string $prefix;

    protected function setUp(): void
    {
        if (! filter_var(getenv('CLOUDFLARE_KV_INTEGRATION'), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('Set CLOUDFLARE_KV_INTEGRATION=true and Cloudflare credentials to run live tests.');
        }

        foreach (['CLOUDFLARE_ACCOUNT_ID', 'CLOUDFLARE_KV_NAMESPACE_ID', 'CLOUDFLARE_API_TOKEN'] as $variable) {
            if (! is_string(getenv($variable)) || getenv($variable) === '') {
                $this->markTestSkipped("{$variable} is not set.");
            }
        }

        $this->prefix = 'it-'.Str::lower(Str::random(12)).':';

        parent::setUp();

        // Real backoff against the real API.
        Sleep::fake(false);
    }

    protected function tearDown(): void
    {
        if (isset($this->app)) {
            try {
                $this->cache()->flush();
            } catch (Throwable) {
                // Best effort cleanup; keys expire on their own as well.
            }
        }

        parent::tearDown();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('cache.stores.cloudflare', [
            'driver' => 'cloudflare',
            'account_id' => getenv('CLOUDFLARE_ACCOUNT_ID'),
            'namespace_id' => getenv('CLOUDFLARE_KV_NAMESPACE_ID'),
            'api_token' => getenv('CLOUDFLARE_API_TOKEN'),
            'prefix' => $this->prefix,
            'allow_non_atomic_updates' => true,
        ]);
    }

    /**
     * Poll until the condition holds, allowing for KV's ~60 second propagation window.
     */
    private function assertEventually(callable $condition, int $timeoutSeconds = 75): void
    {
        $deadline = time() + $timeoutSeconds;

        while (! $condition()) {
            if (time() >= $deadline) {
                $this->fail("Condition not met within {$timeoutSeconds} seconds.");
            }

            sleep(5);
        }

        $this->addToAssertionCount(1);
    }

    private function cache(): Repository
    {
        return Cache::store('cloudflare');
    }

    #[Test]
    public function it_round_trips_values_through_the_real_api(): void
    {
        $cache = $this->cache();

        $this->assertTrue($cache->put('user:1', ['name' => 'Ada'], 120));
        $this->assertSame(['name' => 'Ada'], $cache->get('user:1'));
        $this->assertSame('computed', $cache->remember('remembered', 120, fn (): string => 'computed'));
        $this->assertTrue($cache->forever('forever', 42));
        $this->assertSame(42, $cache->get('forever'));
        $this->assertSame(43, $cache->increment('forever'));

        // Deletes are eventually consistent: even the same client can read the old
        // value for up to ~60 seconds after forget().
        $this->assertTrue($cache->forget('user:1'));
        $this->assertEventually(fn (): bool => $cache->get('user:1') === null);
    }

    #[Test]
    public function eloquent_collections_round_trip_with_laravel_13_defaults(): void
    {
        $this->app['config']->set('cache.serializable_classes', false);

        $products = new EloquentCollection([new Product(['id' => 1, 'name' => 'Lamp'])]);
        $this->cache()->put('products', $products, 120);

        $fromKv = $this->cache()->get('products');

        $this->assertInstanceOf(EloquentCollection::class, $fromKv);
        $this->assertInstanceOf(Product::class, $fromKv->first());
        $this->assertSame('Lamp', $fromKv->first()->name);
    }

    #[Test]
    public function it_uses_the_bulk_endpoints(): void
    {
        $cache = $this->cache();

        $this->assertTrue($cache->putMany(['a' => 1, 'b' => [2], 'key with space' => 'three'], 120));
        $this->assertSame(
            ['a' => 1, 'b' => [2], 'key with space' => 'three', 'missing' => null],
            $cache->many(['a', 'b', 'key with space', 'missing']),
        );
    }

    #[Test]
    public function short_ttls_expire_logically(): void
    {
        $cache = $this->cache();
        $cache->put('short', 'value', 2);

        $this->assertSame('value', $cache->get('short'));
        sleep(3);
        $this->assertNull($cache->get('short'));
    }

    #[Test]
    public function flush_removes_only_this_runs_prefix(): void
    {
        $cache = $this->cache();
        $cache->putMany(['x' => 1, 'y' => 2], 120);

        // KV listings are eventually consistent; give the new keys time to appear.
        sleep(10);

        $this->assertTrue($cache->flush());
        $this->assertNull($cache->get('x'));
    }

    #[Test]
    public function invalid_tokens_are_reported_as_authentication_errors(): void
    {
        $this->app['config']->set('cache.stores.bad-token', ['api_token' => 'invalid-token'] + $this->app['config']->get('cache.stores.cloudflare'));

        $this->expectException(CloudflareKVAuthenticationException::class);

        Cache::store('bad-token')->get('anything');
    }
}
