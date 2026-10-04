<?php

declare(strict_types=1);

namespace Kopaing\CloudflareKV;

use Closure;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\Factory as HttpFactory;
use Kopaing\CloudflareKV\Contracts\CloudflareKVClientInterface;
use Kopaing\CloudflareKV\Exceptions\CloudflareKVConfigurationException;
use Kopaing\CloudflareKV\Support\KeyFormatter;
use Kopaing\CloudflareKV\Support\StoreConfig;
use Kopaing\CloudflareKV\Support\ValueSerializer;

/**
 * Builds Cloudflare KV cache stores from configuration.
 *
 * Configuration precedence, highest first (a null value counts as "not set"):
 *
 *  1. The store's own entry in config/cache.php ("stores.<name>").
 *  2. The package defaults in config/cloudflare-kv.php (publishable, env-driven).
 *  3. Laravel's cache-wide settings: "cache.prefix" for the prefix and
 *     "cache.serializable_classes" for allowed classes; "app.key" for the signing key.
 *
 * "retry" and "flush" are merged one level deep, so a store can override a single sub-key.
 */
final class CloudflareKVManager
{
    public const DRIVER = 'cloudflare';

    /** @var (Closure(StoreConfig): CloudflareKVClientInterface)|null */
    private ?Closure $clientFactory = null;

    public function __construct(
        private readonly Container $app,
    ) {
    }

    /**
     * Replace how API clients are built (custom transports, decorators, test doubles).
     *
     * @param  (Closure(StoreConfig): CloudflareKVClientInterface)|null  $factory
     */
    public function useClientFactory(?Closure $factory): void
    {
        $this->clientFactory = $factory;
    }

    /**
     * Build a store for one cache store configuration array.
     *
     * @param  array<mixed>  $config
     */
    public function makeStore(array $config): CloudflareKVStore
    {
        $storeConfig = StoreConfig::fromArray($this->resolveConfig($config));
        $events = $storeConfig->events ? $this->events() : null;

        $client = $this->makeClient($storeConfig);
        $keys = new KeyFormatter($storeConfig->prefix);
        $serializer = new ValueSerializer(
            $storeConfig->serializer,
            $storeConfig->signingKey,
            $storeConfig->serializableClasses,
            $storeConfig->encrypt ? $this->encrypter() : null,
        );

        if ($storeConfig->lockStore === null) {
            return new CloudflareKVStore(
                $client,
                $keys,
                $serializer,
                $storeConfig->allowNonAtomicUpdates,
                $storeConfig->foreverTtl,
                $storeConfig->bulkGet,
                $storeConfig->flushEnabled,
                $storeConfig->flushWithoutPrefix,
                $events,
            );
        }

        $lockStore = $storeConfig->lockStore;

        if (isset($config['store']) && $config['store'] === $lockStore) {
            throw new CloudflareKVConfigurationException(
                'A Cloudflare KV store cannot use itself as its "lock_store".',
            );
        }

        return new CloudflareKVLockDelegatingStore(
            $client,
            $keys,
            $serializer,
            fn (): LockProvider => $this->resolveLockProvider($lockStore),
            $storeConfig->allowNonAtomicUpdates,
            $storeConfig->foreverTtl,
            $storeConfig->bulkGet,
            $storeConfig->flushEnabled,
            $storeConfig->flushWithoutPrefix,
            $events,
        );
    }

    public function makeClient(StoreConfig $config): CloudflareKVClientInterface
    {
        if ($this->clientFactory !== null) {
            return ($this->clientFactory)($config);
        }

        return CloudflareKVClient::fromConfig(
            $this->app->make(HttpFactory::class),
            $config,
            $config->events ? $this->events() : null,
        );
    }

    /**
     * Merge store, package and framework configuration (see class docblock).
     *
     * @param  array<mixed>  $config
     * @return array<mixed>
     */
    public function resolveConfig(array $config): array
    {
        $config = self::withoutNulls($config);
        $defaults = self::withoutNulls((array) $this->config()->get('cloudflare-kv', []));

        $resolved = array_merge($defaults, $config);

        foreach (['retry', 'flush'] as $nested) {
            $resolved[$nested] = array_merge(
                is_array($defaults[$nested] ?? null) ? self::withoutNulls($defaults[$nested]) : [],
                is_array($config[$nested] ?? null) ? self::withoutNulls($config[$nested]) : [],
            );
        }

        $resolved['prefix'] ??= $this->config()->get('cache.prefix', '');
        $resolved['signing_key'] ??= $this->config()->get('app.key');
        $resolved['serializable_classes'] ??= $this->config()->get('cache.serializable_classes') ?? true;

        return $resolved;
    }

    private function resolveLockProvider(string $name): LockProvider
    {
        $store = $this->app->make(CacheFactory::class)->store($name)->getStore();

        if ($store instanceof CloudflareKVStore || ! $store instanceof LockProvider) {
            throw new CloudflareKVConfigurationException(sprintf(
                'The "lock_store" [%s] must be a lock-capable store such as redis, database, memcached or dynamodb.',
                $name,
            ));
        }

        return $store;
    }

    private function encrypter(): StringEncrypter
    {
        $encrypter = $this->app->make('encrypter');

        if (! $encrypter instanceof StringEncrypter) {
            throw new CloudflareKVConfigurationException(
                'The "encrypt" option requires the application encrypter to implement StringEncrypter.',
            );
        }

        return $encrypter;
    }

    private function events(): ?Dispatcher
    {
        return $this->app->bound('events') ? $this->app->make(Dispatcher::class) : null;
    }

    private function config(): ConfigRepository
    {
        return $this->app->make(ConfigRepository::class);
    }

    /**
     * @param  array<mixed>  $values
     * @return array<mixed>
     */
    private static function withoutNulls(array $values): array
    {
        return array_filter($values, static fn (mixed $value): bool => $value !== null);
    }
}
