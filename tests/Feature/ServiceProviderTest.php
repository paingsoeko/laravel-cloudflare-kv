<?php

declare(strict_types=1);

namespace Kopaing\CloudflareKV\Tests\Feature;

use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\ServiceProvider;
use Kopaing\CloudflareKV\CloudflareKVLockDelegatingStore;
use Kopaing\CloudflareKV\CloudflareKVManager;
use Kopaing\CloudflareKV\CloudflareKVServiceProvider;
use Kopaing\CloudflareKV\CloudflareKVStore;
use Kopaing\CloudflareKV\Contracts\CloudflareKVClientInterface;
use Kopaing\CloudflareKV\Exceptions\CloudflareKVConfigurationException;
use Kopaing\CloudflareKV\Support\StoreConfig;
use Kopaing\CloudflareKV\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class ServiceProviderTest extends TestCase
{
    #[Test]
    public function the_cloudflare_driver_is_registered_with_the_cache_manager(): void
    {
        $repository = Cache::store('cloudflare');

        $this->assertInstanceOf(Repository::class, $repository);
        $this->assertInstanceOf(CloudflareKVStore::class, $repository->getStore());
        $this->assertNotInstanceOf(CloudflareKVLockDelegatingStore::class, $repository->getStore());
        $this->assertSame('test:', $repository->getStore()->getPrefix());
        $this->assertSame($repository, Cache::store('cloudflare'), 'Stores are resolved once, like any Laravel store.');
    }

    #[Test]
    public function the_provider_is_declared_for_package_discovery(): void
    {
        $composer = json_decode((string) file_get_contents(__DIR__.'/../../composer.json'), true);

        $this->assertSame([CloudflareKVServiceProvider::class], $composer['extra']['laravel']['providers']);
        $this->assertSame('src/', $composer['autoload']['psr-4']['Kopaing\\CloudflareKV\\']);
    }

    #[Test]
    public function the_package_config_is_merged_and_publishable(): void
    {
        $this->assertSame(3, config('cloudflare-kv.retry.times'));
        $this->assertSame('php', config('cloudflare-kv.serializer'));

        $paths = ServiceProvider::pathsToPublish(CloudflareKVServiceProvider::class, 'cloudflare-kv-config');

        $this->assertCount(1, $paths);
        $this->assertFileExists((string) array_key_first($paths));
        $this->assertSame($this->app->configPath('cloudflare-kv.php'), reset($paths));
    }

    #[Test]
    public function the_config_file_can_be_cached(): void
    {
        $config = require __DIR__.'/../../config/cloudflare-kv.php';

        // config:cache var_exports the configuration; closures or objects would break it.
        $exported = var_export($config, true);
        $this->assertSame($config, eval('return '.$exported.';'));
    }

    #[Test]
    public function store_options_take_precedence_over_package_defaults(): void
    {
        $this->app['config']->set('cloudflare-kv.timeout', 30);
        $this->app['config']->set('cloudflare-kv.retry', ['times' => 5, 'sleep' => 100, 'max_sleep' => 900]);

        $resolved = $this->app->make(CloudflareKVManager::class)->resolveConfig([
            'driver' => 'cloudflare',
            'timeout' => 3,
            'retry' => ['times' => 2],
            'prefix' => null,
        ]);

        $this->assertSame(3, $resolved['timeout']);
        $this->assertSame(['times' => 2, 'sleep' => 100, 'max_sleep' => 900], $resolved['retry']);
    }

    #[Test]
    public function credentials_can_come_from_the_package_config_alone(): void
    {
        $this->app['config']->set('cloudflare-kv.account_id', self::ACCOUNT_ID);
        $this->app['config']->set('cloudflare-kv.namespace_id', self::NAMESPACE_ID);
        $this->app['config']->set('cloudflare-kv.api_token', self::API_TOKEN);
        $this->app['config']->set('cache.stores.minimal', ['driver' => 'cloudflare']);
        $this->app['config']->set('cache.prefix', 'myapp-cache-');

        $store = Cache::store('minimal')->getStore();

        $this->assertInstanceOf(CloudflareKVStore::class, $store);
        $this->assertSame('myapp-cache-', $store->getPrefix(), 'Falls back to cache.prefix.');
    }

    #[Test]
    public function the_signing_key_and_serializable_classes_fall_back_to_laravel(): void
    {
        $this->app['config']->set('cache.serializable_classes', false);

        $resolved = $this->app->make(CloudflareKVManager::class)->resolveConfig(['driver' => 'cloudflare']);

        $this->assertSame($this->app['config']->get('app.key'), $resolved['signing_key']);
        $this->assertFalse($resolved['serializable_classes']);
    }

    #[Test]
    public function missing_credentials_fail_when_the_store_is_resolved(): void
    {
        $this->app['config']->set('cache.stores.broken', ['driver' => 'cloudflare']);

        $this->expectException(CloudflareKVConfigurationException::class);
        $this->expectExceptionMessage('CLOUDFLARE_ACCOUNT_ID');

        Cache::store('broken');
    }

    #[Test]
    public function the_php_serializer_requires_a_key(): void
    {
        $this->app['config']->set('app.key', null);
        $this->app->make('cache')->forgetDriver('cloudflare');

        $this->expectException(CloudflareKVConfigurationException::class);
        $this->expectExceptionMessage('signing key');

        Cache::store('cloudflare');
    }

    #[Test]
    public function a_lock_store_produces_a_lock_provider(): void
    {
        $this->configureStore(['lock_store' => 'array']);

        $this->assertInstanceOf(CloudflareKVLockDelegatingStore::class, Cache::store('cloudflare')->getStore());
    }

    #[Test]
    public function the_client_can_be_replaced(): void
    {
        $client = $this->createMock(CloudflareKVClientInterface::class);
        $client->expects($this->once())->method('get')->with('test:k')->willReturn(null);

        $this->app->make(CloudflareKVManager::class)->useClientFactory(function (StoreConfig $config) use ($client) {
            $this->assertSame(self::NAMESPACE_ID, $config->namespaceId);

            return $client;
        });
        $this->app->make('cache')->forgetDriver('cloudflare');

        $this->assertNull(Cache::store('cloudflare')->get('k'));
        $this->assertSame($client, Cache::store('cloudflare')->getStore()->getClient());
    }
}
