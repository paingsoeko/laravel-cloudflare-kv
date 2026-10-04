<?php

declare(strict_types=1);

namespace Kopaing\CloudflareKV\Support;

/**
 * A decoded cache entry: the cached value plus its logical expiration.
 */
final readonly class Payload
{
    public function __construct(
        public mixed $value,
        public ?int $expiresAt,
    ) {
    }

    public function isExpired(int $now): bool
    {
        return $this->expiresAt !== null && $this->expiresAt <= $now;
    }

    /**
     * Seconds until logical expiry, or null when the entry never expires.
     */
    public function remainingSeconds(int $now): ?int
    {
        return $this->expiresAt === null ? null : max(0, $this->expiresAt - $now);
    }
}
