<?php

declare(strict_types=1);

namespace Kopaing\CloudflareKV\Exceptions;

/**
 * The operation cannot be implemented honestly on top of Cloudflare Workers KV.
 */
class CloudflareKVUnsupportedOperationException extends CloudflareKVException
{
}
