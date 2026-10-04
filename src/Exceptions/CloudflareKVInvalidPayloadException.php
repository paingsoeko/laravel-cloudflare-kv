<?php

declare(strict_types=1);

namespace Kopaing\CloudflareKV\Exceptions;

/**
 * A stored value is not a valid, authentic payload written by this package.
 */
class CloudflareKVInvalidPayloadException extends CloudflareKVException
{
}
