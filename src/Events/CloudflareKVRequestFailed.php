<?php

declare(strict_types=1);

namespace Kopaing\CloudflareKV\Events;

/**
 * A Cloudflare KV API request failed for good; the exception is thrown right after this event.
 *
 * $exception is the exception class name. No keys, values or credentials are included.
 */
final readonly class CloudflareKVRequestFailed
{
    public function __construct(
        public string $operation,
        public string $exception,
        public ?int $status,
        public float $durationMs,
        public int $attempts,
    ) {
    }
}
