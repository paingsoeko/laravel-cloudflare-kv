<?php

declare(strict_types=1);

namespace Kopaing\CloudflareKV\Exceptions;

/**
 * The Cloudflare API could not be reached (DNS, TLS, connect or read timeout) after all retry attempts.
 */
class CloudflareKVConnectionException extends CloudflareKVException
{
}
