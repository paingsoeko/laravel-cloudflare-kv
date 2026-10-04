<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Cloudflare Workers KV cache defaults
|--------------------------------------------------------------------------
|
| These values are defaults for every cache store that uses the "cloudflare"
| driver. Any option set on the store itself in config/cache.php takes
| precedence; a null value means "not set" and falls through to the next
| level. Precedence, highest first:
|
|   1. config/cache.php  -> stores.<name>.<option>
|   2. this file         -> cloudflare-kv.<option>
|   3. Laravel           -> cache.prefix, app.key
|
| The minimal store definition is therefore:
|
|   'cloudflare' => ['driver' => 'cloudflare'],
|
*/

return [

    // Cloudflare account and KV namespace (32-character hex IDs from the dashboard).
    'account_id' => env('CLOUDFLARE_ACCOUNT_ID'),
    'namespace_id' => env('CLOUDFLARE_KV_NAMESPACE_ID'),

    // API token with only "Account > Workers KV Storage > Edit" permission.
    'api_token' => env('CLOUDFLARE_API_TOKEN'),

    // Key prefix. Null falls back to cache.prefix. A prefix ending in a letter or
    // digit gets ":" appended. flush() only ever deletes keys with this prefix.
    'prefix' => env('CLOUDFLARE_KV_PREFIX'),

    'base_url' => env('CLOUDFLARE_API_BASE_URL', 'https://api.cloudflare.com/client/v4'),

    // HTTP timeouts in seconds.
    'timeout' => 10,
    'connect_timeout' => 5,

    // Transient failures (connection errors, 408, 429, 5xx). "times" counts total
    // attempts. "sleep" is the first backoff in ms (doubled each retry, with jitter),
    // capped at "max_sleep" ms. A Retry-After longer than "max_sleep" fails fast.
    'retry' => [
        'times' => 3,
        'sleep' => 200,
        'max_sleep' => 2000,
    ],

    // "php": serialize()/unserialize() of any value, HMAC-signed (requires APP_KEY or
    //        signing_key); only payloads with a valid signature are unserialized.
    // "json": null, scalars and arrays only. No PHP objects can ever be created.
    'serializer' => env('CLOUDFLARE_KV_SERIALIZER', 'php'),

    // Secret used to sign payloads. Null falls back to APP_KEY. Rotating it turns
    // every existing entry into a cache miss.
    'signing_key' => env('CLOUDFLARE_KV_SIGNING_KEY'),

    // Classes unserialize() may instantiate (true, false or a list of class names).
    // Null means true. Laravel's cache.serializable_classes (false in new Laravel 13
    // apps) is intentionally not inherited: only payloads signed with your key are
    // ever unserialized, so Eloquent models and collections round-trip by default.
    'serializable_classes' => null,

    // Encrypt values with Laravel's Encrypter (APP_KEY) before they leave the app.
    'encrypt' => env('CLOUDFLARE_KV_ENCRYPT', false),

    // increment(), decrement() and touch() need a read-modify-write, which is NOT
    // atomic on KV: concurrent callers can lose updates. They throw unless enabled.
    'allow_non_atomic_updates' => false,

    // If set (>= 60), forever() writes expire after this many seconds instead of never.
    'forever_ttl' => null,

    // Use POST /bulk/get (100 keys per request) for many(). Disable to fall back to
    // one GET per key.
    'bulk_get' => true,

    'flush' => [
        // Set to false to make Cache::flush() / cache:clear throw for this store.
        'enabled' => true,
        // An empty prefix would delete the whole namespace; refused unless true.
        'allow_without_prefix' => false,
    ],

    // A lock-capable store (e.g. "redis") that Cache::lock() is delegated to.
    // Null means locks are unsupported on this store.
    'lock_store' => env('CLOUDFLARE_KV_LOCK_STORE'),

    // Dispatch CloudflareKVRequest* / CloudflareKVPayloadRejected events.
    'events' => true,

];
