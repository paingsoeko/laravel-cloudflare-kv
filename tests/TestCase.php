<?php

declare(strict_types=1);

namespace Kopaing\CloudflareKV\Tests;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Kopaing\CloudflareKV\CloudflareKVServiceProvider;
use Kopaing\CloudflareKV\Tests\Fakes\FakeCloudflareKVApi;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    public const ACCOUNT_ID = '0123456789abcdef0123456789abcdef';

    public const NAMESPACE_ID = 'fedcba9876543210fedcba9876543210';

    public const API_TOKEN = 'test-token-SECRET-value-123';

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
    }

    protected function getPackageProviders($app): array
    {
        return [CloudflareKVServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        tap($app->make(Repository::class), function (Repository $config): void {
            $config->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
            $config->set('cache.stores.cloudflare', [
                'driver' => 'cloudflare',
                'account_id' => self::ACCOUNT_ID,
                'namespace_id' => self::NAMESPACE_ID,
                'api_token' => self::API_TOKEN,
                'prefix' => 'test:',
            ]);
        });
    }

    /**
     * Route every HTTP request to a stateful in-memory imitation of the KV REST API.
     */
    protected function fakeApi(): FakeCloudflareKVApi
    {
        $api = new FakeCloudflareKVApi(self::ACCOUNT_ID, self::NAMESPACE_ID, self::API_TOKEN);

        Http::preventStrayRequests();
        Http::fake(fn ($request) => $api->handle($request));

        return $api;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    protected function configureStore(array $options): void
    {
        $config = $this->app->make(Repository::class);
        $config->set('cache.stores.cloudflare', array_merge((array) $config->get('cache.stores.cloudflare'), $options));
        $this->app->make('cache')->forgetDriver('cloudflare');
    }
}
