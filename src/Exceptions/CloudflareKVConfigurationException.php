<?php

declare(strict_types=1);

namespace Kopaing\CloudflareKV\Exceptions;

/**
 * The store configuration is missing, invalid, or forbids the requested operation.
 */
class CloudflareKVConfigurationException extends CloudflareKVException
{
}
