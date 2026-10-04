<?php

declare(strict_types=1);

namespace Kopaing\CloudflareKV;

use Closure;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Events\Dispatcher;
use Kopaing\CloudflareKV\Contracts\CloudflareKVClientInterface;
use Kopaing\CloudflareKV\Support\KeyFormatter;
use Kopaing\CloudflareKV\Support\ValueSerializer;

/**
 * A Cloudflare KV store whose atomic locks live in a different, lock-capable store.
 *
 * Workers KV has no compare-and-set primitive, so a lock built on KV could be held by two
 * processes at once. When "lock_store" is configured (e.g. "redis"), lock() and
 * restoreLock() are forwarded to that store, so Cache::lock(), Cache::flexible() and
 * unique jobs get real mutual exclusion while values stay in KV.
 */
class CloudflareKVLockDelegatingStore extends CloudflareKVStore implements LockProvider
{
    private ?LockProvider $resolvedLocks = null;

    /**
     * @param  Closure(): LockProvider  $lockProvider  Resolved lazily on first use.
     */
    public function __construct(
        CloudflareKVClientInterface $client,
        KeyFormatter $keys,
        ValueSerializer $serializer,
        private readonly Closure $lockProvider,
        bool $allowNonAtomicUpdates = false,
        ?int $foreverTtl = null,
        bool $bulkGet = true,
        bool $flushEnabled = true,
        bool $flushWithoutPrefix = false,
        ?Dispatcher $events = null,
    ) {
        parent::__construct(
            $client,
            $keys,
            $serializer,
            $allowNonAtomicUpdates,
            $foreverTtl,
            $bulkGet,
            $flushEnabled,
            $flushWithoutPrefix,
            $events,
        );
    }

    /**
     * @param  string  $name
     * @param  int  $seconds
     * @param  string|null  $owner
     */
    public function lock($name, $seconds = 0, $owner = null): Lock
    {
        return $this->locks()->lock($name, $seconds, $owner);
    }

    /**
     * @param  string  $name
     * @param  string  $owner
     */
    public function restoreLock($name, $owner): Lock
    {
        return $this->locks()->restoreLock($name, $owner);
    }

    public function lockProvider(): LockProvider
    {
        return $this->locks();
    }

    private function locks(): LockProvider
    {
        return $this->resolvedLocks ??= ($this->lockProvider)();
    }
}
