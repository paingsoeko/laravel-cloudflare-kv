<?php

declare(strict_types=1);

namespace Kopaing\CloudflareKV\Events;

/**
 * A Cloudflare KV API request failed transiently and is about to be retried.
 *
 * $reason is "connection" or "http_<status>".
 */
final readonly class CloudflareKVRequestRetrying
{
    public function __construct(
        public string $operation,
        public int $attempt,
        public int $delayMs,
        public string $reason,
    ) {
    }
}
