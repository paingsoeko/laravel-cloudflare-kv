<?php

declare(strict_types=1);

namespace Kopaing\CloudflareKV;

use Illuminate\Contracts\Cache\Store;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\InteractsWithTime;
use Kopaing\CloudflareKV\Contracts\CloudflareKVClientInterface;
use Kopaing\CloudflareKV\Events\CloudflareKVPayloadRejected;
use Kopaing\CloudflareKV\Exceptions\CloudflareKVConfigurationException;
use Kopaing\CloudflareKV\Exceptions\CloudflareKVInvalidPayloadException;
use Kopaing\CloudflareKV\Exceptions\CloudflareKVUnsupportedOperationException;
use Kopaing\CloudflareKV\Support\KeyFormatter;
use Kopaing\CloudflareKV\Support\Payload;
use Kopaing\CloudflareKV\Support\ValueSerializer;

/**
 * Laravel cache store backed by Cloudflare Workers KV.
 *
 * Expiration: every entry carries its own logical expiry in the payload, which is checked
 * on every read, so expired entries are never returned. The Cloudflare-side TTL is set to
 * max(seconds, 60) - Cloudflare's minimum - so KV never deletes an entry before Laravel
 * considers it expired; short-TTL entries simply linger (unreadable) for up to 60 seconds.
 *
 * Consistency: Workers KV is eventually consistent. A write may take up to ~60 seconds to
 * become visible in other locations, and there are no atomic primitives. Operations that
 * would need atomicity (increment, decrement, touch) are rejected unless the application
 * explicitly opts in to non-atomic read-modify-write via "allow_non_atomic_updates".
 * Tags are not supported, and locks are only available by delegating to another store.
 */
class CloudflareKVStore implements Store
{
    use InteractsWithTime;

    public function __construct(
        protected readonly CloudflareKVClientInterface $client,
        protected readonly KeyFormatter $keys,
        protected readonly ValueSerializer $serializer,
        protected readonly bool $allowNonAtomicUpdates = false,
        protected readonly ?int $foreverTtl = null,
        protected readonly bool $bulkGet = true,
        protected readonly bool $flushEnabled = true,
        protected readonly bool $flushWithoutPrefix = false,
        protected readonly ?Dispatcher $events = null,
    ) {
    }

    /**
     * @param  string  $key
     */
    public function get($key): mixed
    {
        $storageKey = $this->keys->format((string) $key);
        $raw = $this->client->get($storageKey);

        return $raw === null ? null : $this->decode($raw, $storageKey)?->value;
    }

    /**
     * @param  array<int, string>  $keys
     * @return array<string, mixed>
     */
    public function many(array $keys): array
    {
        $storageKeys = [];

        foreach ($keys as $key) {
            $storageKeys[(string) $key] = $this->keys->format((string) $key);
        }

        if ($storageKeys === []) {
            return [];
        }

        $raw = $this->bulkGet
            ? $this->client->getMany(array_values(array_unique($storageKeys)))
            : array_map($this->client->get(...), array_combine($storageKeys, $storageKeys));

        $results = [];

        foreach ($storageKeys as $key => $storageKey) {
            $value = $raw[$storageKey] ?? null;
            $results[$key] = $value === null ? null : $this->decode($value, $storageKey)?->value;
        }

        return $results;
    }

    /**
     * @param  string  $key
     * @param  int  $seconds
     */
    public function put($key, $value, $seconds): bool
    {
        $seconds = max(1, (int) $seconds);
        $storageKey = $this->keys->format((string) $key);

        $this->client->put(
            $storageKey,
            $this->serializer->encode($value, $this->currentTime() + $seconds, $storageKey),
            self::kvTtl($seconds),
        );

        return true;
    }

    /**
     * Store many items with one bulk request per 10,000 keys.
     *
     * Returns false if Cloudflare reported some keys as unsuccessful after retries; the
     * other keys were written. Bulk writes are not transactional.
     *
     * @param  array<string, mixed>  $values
     * @param  int  $seconds
     */
    public function putMany(array $values, $seconds): bool
    {
        if ($values === []) {
            return true;
        }

        $seconds = max(1, (int) $seconds);
        $expiresAt = $this->currentTime() + $seconds;
        $encoded = [];

        foreach ($values as $key => $value) {
            $storageKey = $this->keys->format((string) $key);
            $encoded[$storageKey] = $this->serializer->encode($value, $expiresAt, $storageKey);
        }

        if (count($encoded) === 1) {
            $this->client->put(array_key_first($encoded), reset($encoded), self::kvTtl($seconds));

            return true;
        }

        return $this->client->putMany($encoded, self::kvTtl($seconds)) === [];
    }

    /**
     * Not atomic: see "allow_non_atomic_updates".
     *
     * @param  string  $key
     * @param  int|float  $value
     */
    public function increment($key, $value = 1): int|bool
    {
        $this->assertNonAtomicUpdatesAllowed('increment');

        $storageKey = $this->keys->format((string) $key);
        $raw = $this->client->get($storageKey);
        $payload = $raw === null ? null : $this->decode($raw, $storageKey);

        if ($payload === null) {
            // Like Redis INCRBY on a missing key: start from zero and never expire.
            $current = 0;
            $expiresAt = $this->foreverTtl === null ? null : $this->currentTime() + $this->foreverTtl;
        } else {
            if (! is_int($payload->value) && ! (is_string($payload->value) && preg_match('/\A-?\d+\z/', $payload->value) === 1)) {
                return false;
            }

            $current = (int) $payload->value;
            $expiresAt = $payload->expiresAt;
        }

        $new = $current + (int) $value;
        $remaining = $expiresAt === null ? null : max(1, $expiresAt - $this->currentTime());

        $this->client->put(
            $storageKey,
            $this->serializer->encode($new, $expiresAt, $storageKey),
            $remaining === null ? null : self::kvTtl($remaining),
        );

        return $new;
    }

    /**
     * Not atomic: see "allow_non_atomic_updates".
     *
     * @param  string  $key
     * @param  int|float  $value
     */
    public function decrement($key, $value = 1): int|bool
    {
        $this->assertNonAtomicUpdatesAllowed('decrement');

        return $this->increment($key, (int) $value * -1);
    }

    /**
     * @param  string  $key
     */
    public function forever($key, $value): bool
    {
        if ($this->foreverTtl !== null) {
            return $this->put($key, $value, $this->foreverTtl);
        }

        $storageKey = $this->keys->format((string) $key);

        $this->client->put($storageKey, $this->serializer->encode($value, null, $storageKey));

        return true;
    }

    /**
     * Change the expiration of an existing item (Laravel 13 Store contract).
     *
     * KV has no "expire" command, so this re-writes the value: not atomic, see "allow_non_atomic_updates".
     *
     * @param  string  $key
     * @param  int  $seconds
     */
    public function touch($key, $seconds): bool
    {
        $this->assertNonAtomicUpdatesAllowed('touch');

        $storageKey = $this->keys->format((string) $key);
        $raw = $this->client->get($storageKey);
        $payload = $raw === null ? null : $this->decode($raw, $storageKey);

        if ($payload === null) {
            return false;
        }

        return $this->put($key, $payload->value, $seconds);
    }

    /**
     * @param  string  $key
     */
    public function forget($key): bool
    {
        $this->client->delete($this->keys->format((string) $key));

        return true;
    }

    /**
     * Delete every key that starts with this store's prefix.
     *
     * Keys are listed page by page (1,000 per page) and deleted in bulk (up to 10,000 per
     * request). Because KV listing is eventually consistent, keys written in the last
     * ~60 seconds may be missed, and deleted keys may still be readable at some edge
     * locations for up to ~60 seconds. Returns false if some keys could not be deleted.
     */
    public function flush(): bool
    {
        if (! $this->flushEnabled) {
            throw new CloudflareKVConfigurationException(
                'Flushing this Cloudflare KV cache store is disabled ("flush.enabled" is false).',
            );
        }

        $prefix = $this->keys->prefix();

        if ($prefix === '' && ! $this->flushWithoutPrefix) {
            throw new CloudflareKVConfigurationException(
                'Refusing to flush a Cloudflare KV cache store without a prefix: this would delete every key in the '
                .'namespace. Configure a "prefix", or set "flush.allow_without_prefix" to true if the namespace is '
                .'dedicated to this cache.',
            );
        }

        $pending = [];
        $failed = [];
        $cursor = null;
        $seenCursors = [];

        do {
            $page = $this->client->listKeys($prefix, $cursor);

            foreach ($page['keys'] as $name) {
                // Defence in depth: never delete a key outside our prefix, whatever the API returned.
                if ($prefix === '' || str_starts_with($name, $prefix)) {
                    $pending[] = $name;
                }
            }

            if (count($pending) >= CloudflareKVClient::MAX_BULK_DELETE_KEYS) {
                $failed = [...$failed, ...$this->client->deleteMany($pending)];
                $pending = [];
            }

            $cursor = $page['cursor'];

            if ($cursor !== null && isset($seenCursors[$cursor])) {
                break;
            }

            if ($cursor !== null) {
                $seenCursors[$cursor] = true;
            }
        } while ($cursor !== null);

        if ($pending !== []) {
            $failed = [...$failed, ...$this->client->deleteMany($pending)];
        }

        return $failed === [];
    }

    /**
     * Locks are not implemented on KV (no compare-and-set). This method exists only to
     * give a clear error; the store deliberately does not implement LockProvider.
     *
     * @param  string  $name
     * @param  int  $seconds
     * @param  string|null  $owner
     */
    public function lock($name, $seconds = 0, $owner = null): mixed
    {
        throw self::locksUnsupported();
    }

    /**
     * @param  string  $name
     * @param  string  $owner
     */
    public function restoreLock($name, $owner): mixed
    {
        throw self::locksUnsupported();
    }

    public function getPrefix(): string
    {
        return $this->keys->prefix();
    }

    public function getClient(): CloudflareKVClientInterface
    {
        return $this->client;
    }

    /**
     * Cloudflare-side TTL: never shorter than the logical TTL, never below the KV minimum.
     */
    protected static function kvTtl(int $seconds): int
    {
        return max($seconds, CloudflareKVClient::MIN_EXPIRATION_TTL);
    }

    protected function decode(string $raw, string $storageKey): ?Payload
    {
        try {
            $payload = $this->serializer->decode($raw, $storageKey);
        } catch (CloudflareKVInvalidPayloadException $e) {
            $this->events?->dispatch(new CloudflareKVPayloadRejected($e->getMessage()));

            return null;
        }

        return $payload->isExpired($this->currentTime()) ? null : $payload;
    }

    protected static function locksUnsupported(): CloudflareKVUnsupportedOperationException
    {
        return new CloudflareKVUnsupportedOperationException(
            'Cloudflare KV cannot provide atomic locks. Set the store\'s "lock_store" option to a lock-capable '
            .'store (e.g. "redis") to delegate Cache::lock(), or call Cache::store(\'redis\')->lock() directly.',
        );
    }

    protected function assertNonAtomicUpdatesAllowed(string $operation): void
    {
        if ($this->allowNonAtomicUpdates) {
            return;
        }

        throw new CloudflareKVUnsupportedOperationException(sprintf(
            'Cloudflare KV cannot perform an atomic %s(). Concurrent requests could lose updates. '
            .'Use a Redis/database store for counters, or set "allow_non_atomic_updates" to true to accept a '
            .'non-atomic read-modify-write.',
            $operation,
        ));
    }
}
