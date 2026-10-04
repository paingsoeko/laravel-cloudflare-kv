<?php

declare(strict_types=1);

namespace Kopaing\CloudflareKV\Exceptions;

/**
 * The API token was rejected (HTTP 401) or lacks permission for the namespace (HTTP 403).
 */
class CloudflareKVAuthenticationException extends CloudflareKVRequestException
{
}
