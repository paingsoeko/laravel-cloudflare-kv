<?php

declare(strict_types=1);

namespace Kopaing\CloudflareKV\Contracts;

/**
 * Low-level access to one Cloudflare Workers KV namespace.
 *
 * Keys passed here are final storage keys (already prefixed and validated by the store).
 * Values are opaque strings. Implementations must throw a
 * Kopaing\CloudflareKV\Exceptions\CloudflareKVException subclass on failure and must
 * never include API credentials in exception messages.
 */
interface CloudflareKVClientInterface
{
    /**
     * Read one value. Returns null when the key does not exist.
     */
    public function get(string $key): ?string;

    /**
     * Read up to any number of values (chunked internally to the API's bulk limit).
     *
     * @param  list<string>  $keys
     * @return array<string, string|null> Keyed by the requested key; null for missing keys.
     */
    public function getMany(array $keys): array;

    /**
     * Write one value. $ttl is the Cloudflare-side expiration in seconds (>= 60) or null for none.
     */
    public function put(string $key, string $value, ?int $ttl = null): void;

    /**
     * Write many values sharing the same Cloudflare-side TTL.
     *
     * @param  array<string, string>  $values
     * @return list<string> Keys that could not be written after all retries.
     */
    public function putMany(array $values, ?int $ttl = null): array;

    /**
     * Delete one key. Deleting a missing key is not an error.
     */
    public function delete(string $key): void;

    /**
     * Delete many keys.
     *
     * @param  list<string>  $keys
     * @return list<string> Keys that could not be deleted after all retries.
     */
    public function deleteMany(array $keys): array;

    /**
     * List one page of key names that start with $prefix.
     *
     * @return array{keys: list<string>, cursor: string|null} cursor is null on the last page.
     */
    public function listKeys(string $prefix, ?string $cursor = null, int $limit = 1000): array;
}
