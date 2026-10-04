<?php

declare(strict_types=1);

namespace Kopaing\CloudflareKV\Exceptions;

use InvalidArgumentException;

/**
 * The value cannot be represented by the configured serializer.
 */
class CloudflareKVSerializationException extends InvalidArgumentException
{
}
