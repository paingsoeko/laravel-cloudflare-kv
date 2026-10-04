<?php

declare(strict_types=1);

namespace Kopaing\CloudflareKV\Events;

/**
 * A stored value was treated as a cache miss because it is not an authentic payload.
 *
 * $reason is one of "malformed", "unsupported_version", "signature" or "encryption".
 * Common causes: APP_KEY / signing key rotation, a value written by another tool,
 * or switching "encrypt" off while encrypted entries still exist.
 */
final readonly class CloudflareKVPayloadRejected
{
    public function __construct(
        public string $reason,
    ) {
    }
}
