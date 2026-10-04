<?php

declare(strict_types=1);

namespace Kopaing\CloudflareKV\Exceptions;

/**
 * Cloudflare answered HTTP 429 and the retry budget (or the Retry-After window) was exhausted.
 */
class CloudflareKVRateLimitException extends CloudflareKVRequestException
{
    /**
     * @param  list<array{code: int|null, message: string}>  $errors
     */
    public function __construct(
        string $message,
        string $operation,
        public readonly ?int $retryAfter = null,
        array $errors = [],
    ) {
        parent::__construct($message, $operation, 429, $errors);
    }
}
