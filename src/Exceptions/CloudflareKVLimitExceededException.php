<?php

declare(strict_types=1);

namespace Kopaing\CloudflareKV\Exceptions;

/**
 * A key, value or request exceeds a documented Cloudflare Workers KV limit.
 */
class CloudflareKVLimitExceededException extends CloudflareKVException
{
}
