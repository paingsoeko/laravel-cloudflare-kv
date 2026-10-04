<?php

declare(strict_types=1);

namespace Kopaing\CloudflareKV;

use Illuminate\Cache\CacheManager;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

final class CloudflareKVServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/cloudflare-kv.php', 'cloudflare-kv');

        $this->app->singleton(CloudflareKVManager::class, static fn (Application $app): CloudflareKVManager => new CloudflareKVManager($app));

        // Register the driver whenever the cache manager is (or already was) resolved.
        // The creator must not be static: CacheManager::extend() rebinds it to itself.
        $this->callAfterResolving('cache', function (CacheManager $cache): void {
            $cache->extend(
                CloudflareKVManager::DRIVER,
                /** @param  array<string, mixed>  $config */
                fn (Application $app, array $config): Repository => $cache->repository(
                    $app->make(CloudflareKVManager::class)->makeStore($config),
                    $config,
                ),
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/cloudflare-kv.php' => $this->app->configPath('cloudflare-kv.php'),
            ], 'cloudflare-kv-config');
        }
    }
}
