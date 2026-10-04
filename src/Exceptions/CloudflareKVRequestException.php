<?php

declare(strict_types=1);

namespace Kopaing\CloudflareKV\Exceptions;

use Throwable;

/**
 * The Cloudflare API answered, but with an error status, an error payload or an unparseable body.
 */
class CloudflareKVRequestException extends CloudflareKVException
{
    /**
     * @param  list<array{code: int|null, message: string}>  $errors
     */
    public function __construct(
        string $message,
        public readonly string $operation,
        public readonly ?int $status = null,
        public readonly array $errors = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status ?? 0, $previous);
    }

    /**
     * @return list<int>
     */
    public function errorCodes(): array
    {
        return array_values(array_filter(array_column($this->errors, 'code'), 'is_int'));
    }
}
