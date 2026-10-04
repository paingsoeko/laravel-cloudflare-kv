<?php

declare(strict_types=1);

namespace Kopaing\CloudflareKV\Events;

/**
 * A Cloudflare KV API request finished successfully (possibly after retries).
 *
 * Carries no keys, values or credentials, only operational metrics.
 */
final readonly class CloudflareKVRequestCompleted
{
    public function __construct(
        public string $operation,
        public int $status,
        public float $durationMs,
        public int $attempts,
    ) {
    }
}
