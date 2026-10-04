<?php

declare(strict_types=1);

namespace Kopaing\CloudflareKV\Support;

use Kopaing\CloudflareKV\Exceptions\CloudflareKVConfigurationException;

/**
 * Maps Laravel cache keys onto valid, prefixed Cloudflare KV key names.
 *
 * Cloudflare KV key names must be 1-512 bytes of printable, non-whitespace characters
 * and may not be "." or "..". Keys that already satisfy this are stored verbatim
 * (prefix + key) so they stay readable in the Cloudflare dashboard. Every other key
 * (whitespace, control characters, invalid UTF-8, too long) is stored as
 * prefix + "~h:" + sha256(key). Keys that themselves start with the "~h:" marker are
 * always hashed too, so the mapping stays injective.
 */
final class KeyFormatter
{
    public const MAX_KEY_BYTES = 512;

    public const MAX_PREFIX_BYTES = 256;

    public const HASH_MARKER = '~h:';

    private readonly string $prefix;

    public function __construct(string $prefix)
    {
        $this->prefix = self::normalizePrefix($prefix);
    }

    /**
     * Validate a prefix and make sure it ends with a delimiter.
     *
     * A prefix ending in a letter or digit gets ":" appended, so "app" cannot match
     * keys belonging to a sibling prefix such as "app2" during a flush.
     */
    public static function normalizePrefix(string $prefix): string
    {
        if ($prefix === '') {
            return '';
        }

        if (strlen($prefix) > self::MAX_PREFIX_BYTES) {
            throw new CloudflareKVConfigurationException(sprintf(
                'The Cloudflare KV cache prefix may be at most %d bytes.',
                self::MAX_PREFIX_BYTES,
            ));
        }

        if (! self::isPrintable($prefix)) {
            throw new CloudflareKVConfigurationException(
                'The Cloudflare KV cache prefix must be valid UTF-8 without whitespace or control characters.',
            );
        }

        if (ctype_alnum(substr($prefix, -1))) {
            $prefix .= ':';
        }

        return $prefix;
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    public function format(string $key): string
    {
        $candidate = $this->prefix.$key;

        if ($this->canStoreVerbatim($key, $candidate)) {
            return $candidate;
        }

        return $this->prefix.self::HASH_MARKER.hash('sha256', $key);
    }

    private function canStoreVerbatim(string $key, string $candidate): bool
    {
        return $candidate !== ''
            && $candidate !== '.'
            && $candidate !== '..'
            && strlen($candidate) <= self::MAX_KEY_BYTES
            && ! str_starts_with($key, self::HASH_MARKER)
            && self::isPrintable($candidate);
    }

    private static function isPrintable(string $value): bool
    {
        // \p{C}: control/format/unassigned/private-use, \p{Z}: whitespace and separators.
        // preg_match returns false for invalid UTF-8, which also rejects the value.
        return preg_match('/\A[^\p{C}\p{Z}]+\z/u', $value) === 1;
    }
}
