# Laravel Cloudflare KV Cache Driver

A Laravel cache store backed by [Cloudflare Workers KV](https://developers.cloudflare.com/kv/), used through Laravel's normal `Cache` API:

```php
Cache::store('cloudflare')->remember('products:featured', 3600, fn () => Product::featured()->get());
```

The goal is to feel like Laravel's Redis driver while being upfront about where KV differs. Workers KV is an eventually consistent, globally replicated key-value store. It has no atomic counters, no compare-and-set, no transactions and no locks. This package does not fake any of those. Operations that would need them are rejected, made opt-in with a clear warning, or delegated to a store that supports them.

**Contents:** [Requirements](#requirements) · [Installation](#installation) · [Cloudflare setup](#cloudflare-setup) · [Configuration](#laravel-configuration) · [Usage](#usage) · [TTL and expiration](#ttl-and-expiration) · [Supported APIs](#supported-and-unsupported-cache-apis) · [Consistency and performance](#consistency-and-performance-limitations) · [Error handling](#error-handling) · [Security](#security-recommendations) · [Testing](#testing) · [Troubleshooting](#troubleshooting) · [Compatibility](#version-compatibility) · [Contributing](#contributing) · [Publishing and upgrading](#publishing-and-upgrading) · [License](#license)

---

## Requirements

- PHP 8.3 or newer
- Laravel 12 or 13 (Laravel 11.33.2+ also works; see [Version compatibility](#version-compatibility))
- A Cloudflare account with a Workers KV namespace
- A Cloudflare API token with the **Workers KV Storage** permission

## Installation

```bash
composer require kopaing/laravel-cloudflare-kv
```

Laravel package discovery registers the service provider automatically.

If you want to publish the package defaults (optional):

```bash
php artisan vendor:publish --tag=cloudflare-kv-config
```

## Cloudflare setup

### 1. Create a KV namespace

Using the dashboard: **Storage & Databases → KV → Create namespace**.

Or using Wrangler:

```bash
npx wrangler kv namespace create laravel-cache
```

Copy the namespace **ID** (32 hex characters), not its title. Your **account ID** is on the dashboard overview page and appears in dashboard URLs.

Use a **separate namespace for each application and environment** (for example `myapp-production-cache` and `myapp-staging-cache`). The namespace is the only hard isolation boundary KV gives you.

### 2. Create an API token

**My Profile → API Tokens → Create Token → Create Custom Token**:

| Setting | Value |
|---|---|
| Permissions | **Account → Workers KV Storage → Edit** |
| Account resources | Include → *only the account that owns the namespace* |
| Client IP filtering | Your servers' egress IPs, if they are static |
| TTL | Set an expiry and rotate the token |

This is the least privilege the driver needs. Don't use a Global API Key. When this was written, the Workers KV Storage permission covered every namespace in the selected account and could not be limited to one namespace. If you need hard isolation, use a dedicated Cloudflare account.

## Laravel configuration

### `.env`

```dotenv
CACHE_STORE=cloudflare          # optional: make it the default store

CLOUDFLARE_ACCOUNT_ID=0123456789abcdef0123456789abcdef
CLOUDFLARE_KV_NAMESPACE_ID=fedcba9876543210fedcba9876543210
CLOUDFLARE_API_TOKEN=your-token
CLOUDFLARE_KV_PREFIX=myapp      # optional, defaults to cache.prefix
```

### `config/cache.php`

Add a store. The smallest valid definition is:

```php
'stores' => [
    'cloudflare' => [
        'driver' => 'cloudflare',
    ],
],
```

Credentials and defaults then come from `config/cloudflare-kv.php`, which reads the env variables above. You can also set every option directly on the store:

```php
'cloudflare' => [
    'driver' => 'cloudflare',
    'account_id' => env('CLOUDFLARE_ACCOUNT_ID'),
    'namespace_id' => env('CLOUDFLARE_KV_NAMESPACE_ID'),
    'api_token' => env('CLOUDFLARE_API_TOKEN'),
    'prefix' => env('CLOUDFLARE_KV_PREFIX', 'laravel:'),
    'timeout' => 10,
    'connect_timeout' => 5,
    'retry' => [
        'times' => 3,
        'sleep' => 200,
    ],
    'lock_store' => 'redis', // optional, see "Locks"
],
```

### Configuration precedence

The first value that isn't `null` wins:

1. The store entry in `config/cache.php` (`stores.<name>.<option>`)
2. The package defaults in `config/cloudflare-kv.php`
3. Laravel defaults: `cache.prefix` (prefix), `cache.serializable_classes`, and `app.key` (signing key)

`retry` and `flush` are merged one level deep, so `'retry' => ['times' => 5]` on a store keeps the default `sleep`. Several stores can share credentials and use different prefixes or namespaces.

The configuration contains no closures, so `php artisan config:cache` is supported. As with any Laravel config, `env()` is only read when the config is built or cached.

### All options

| Option | Default | Description |
|---|---|---|
| `account_id`, `namespace_id`, `api_token` | env | Required. Validated before any request is sent. |
| `prefix` | `cache.prefix` | Key prefix. If it ends in a letter or digit, `:` is appended. `flush()` only deletes keys under this prefix. |
| `base_url` | `https://api.cloudflare.com/client/v4` | Must be `https://`. |
| `timeout` / `connect_timeout` | `10` / `5` | Seconds; fractions are allowed. |
| `retry.times` | `3` | Total attempts, including the first (1–10). |
| `retry.sleep` / `retry.max_sleep` | `200` / `2000` | Backoff base and cap, in ms. |
| `serializer` | `php` | `php` (any serializable value, HMAC-signed) or `json` (scalars and arrays only). |
| `signing_key` | `APP_KEY` | Secret used to sign payloads. |
| `serializable_classes` | `cache.serializable_classes` → `true` | Classes `unserialize()` may create. |
| `encrypt` | `false` | Encrypt values with Laravel's encrypter before they leave the app. |
| `allow_non_atomic_updates` | `false` | Enables `increment`, `decrement` and `touch` as **non-atomic** read-modify-write. |
| `forever_ttl` | `null` | If set (≥ 60), `forever()` entries expire after this many seconds. |
| `bulk_get` | `true` | Use the bulk read endpoint for `many()`. |
| `flush.enabled` | `true` | Set to `false` to make `flush()` / `cache:clear` throw. |
| `flush.allow_without_prefix` | `false` | Allow flushing an unprefixed (whole) namespace. |
| `lock_store` | `null` | A lock-capable store that `lock()` is delegated to. |
| `events` | `true` | Dispatch instrumentation events. |

## Usage

```php
use Illuminate\Support\Facades\Cache;

$kv = Cache::store('cloudflare');

$kv->put('user:1', $user, 3600);
$kv->get('user:1');
$kv->has('user:1');
$kv->remember('products:featured', 3600, fn () => Product::featured()->get()->toArray());
$kv->forever('app:settings', $settings);
$kv->forget('user:1');

$kv->many(['user:1', 'user:2']);                       // 1 bulk request per 100 keys
$kv->putMany(['user:1' => $a, 'user:2' => $b], 3600); // 1 bulk request per 10,000 keys

$kv->flush();                                          // only keys under this store's prefix

Cache::store('cloudflare')->put('tracking:ORDER-1001', [
    'status' => 'shipped',
    'updated_at' => now()->toISOString(),
], 86400);
```

With `CACHE_STORE=cloudflare` you can call `Cache::get()` and the rest without `store()`.

> **Caching Eloquent models on Laravel 13.** New Laravel 13 apps ship with `'serializable_classes' => false` in `config/cache.php`. This driver follows that setting the same way Laravel's Redis and file stores do, so objects come back as `__PHP_Incomplete_Class`. Either cache arrays (`->toArray()`), or list the classes you cache in `cache.serializable_classes` (or in this store's `serializable_classes`).

### Locks

KV can't provide mutual exclusion. Delegate locks to a store that can:

```php
'cloudflare' => ['driver' => 'cloudflare', 'lock_store' => 'redis'],
```

```php
Cache::store('cloudflare')->lock('reports:daily', 60)->get(fn () => ...); // lock lives in Redis
```

`Cache::flexible()`, `ShouldBeUnique` jobs and `withoutOverlapping()` then work too. Values stay in KV; only the lock lives in the other store. Without `lock_store`, `lock()` throws `CloudflareKVUnsupportedOperationException`.

## TTL and expiration

Cloudflare KV doesn't accept expirations shorter than **60 seconds** (`expiration_ttl` ≥ 60). Redis does, so this package handles the gap explicitly:

- Each payload stores its own logical expiry, and every read checks it. **An expired value is never returned**, even if KV still holds it.
- The Cloudflare-side TTL is `max(ttl, 60)`. KV therefore never deletes an entry *before* Laravel considers it expired, and an entry with a short TTL just stays (unreadable) for up to 60 s.
- TTLs of 60 s and longer go to Cloudflare unchanged, so both clocks agree.
- `forever()` writes have no Cloudflare TTL unless you set `forever_ttl`.
- A TTL of zero or less deletes the key, as in Laravel's other stores.
- `DateTimeInterface` and `DateInterval` TTLs work, because Laravel's repository converts them to seconds.

Expiry is checked against your application servers' clocks, so keep them NTP-synced.

## Supported and unsupported cache APIs

| API | Status | Notes |
|---|---|---|
| `get`, `put`, `has`, `missing`, `pull`, `forget` | ✅ | One request each. |
| `remember`, `rememberForever`, `sear` | ✅ | **Not** single-flight: concurrent misses can each run the callback. Wrap the call in a lock if that matters. |
| `many`, `putMany` | ✅ | Bulk endpoints. `putMany` returns `false` if some keys still failed after retries; bulk writes aren't transactional. |
| `forever` | ✅ | |
| `flush` / `cache:clear` | ✅ | Prefix-scoped, paginated, bulk delete. See below. |
| `add` | ⚠️ | Uses Laravel's generic get-then-put fallback, which is **not atomic**. |
| `increment`, `decrement` | ⚠️ opt-in | Throw unless `allow_non_atomic_updates` is `true`. Even then they're **non-atomic** read-modify-write, and concurrent increments can be lost. Use Redis or a database store for counters. |
| `touch` (Laravel 13) | ⚠️ opt-in | KV can't change an expiry without rewriting the value; same rule as `increment`. |
| `lock`, `restoreLock`, `flexible`, `withoutOverlapping` | ⚠️ via `lock_store` | Delegated to another store, otherwise they throw. |
| `tags` | ❌ | `BadMethodCallException`. A KV tag index can't be invalidated reliably under eventual consistency and concurrent writers. |
| Rate limiting (`RateLimiter`, `throttle`) | ❌ | Needs atomic `add` and `increment`. Point `cache.limiter` at Redis or a database store. |
| Sessions on the `cloudflare` cache store | ⚠️ | Works, but a write can take up to ~60 s to be visible in other regions. Not recommended. |
| `Cache::memo()` | ✅ | Laravel's request-scoped memoization (later Laravel 12 releases and 13). This package adds no local cache of its own. |

### Flush safety

`flush()` lists only keys that start with this store's prefix. It pages through them 1,000 at a time and deletes in batches of up to 10,000, and it also drops any listed key that doesn't match the prefix before deleting. It refuses to run with an empty prefix unless `flush.allow_without_prefix` is `true`, and `flush.enabled => false` blocks it completely. Because of eventual consistency, keys written in the last ~60 s may be missed, and deleted keys can stay readable at some edge locations for up to ~60 s.

Prefixes nested inside each other (`app:` and `app:v2:`) **will** be flushed together. Give each app its own namespace, or at least prefixes that don't overlap.

## Consistency and performance limitations

- **Eventual consistency.** A write can take up to 60 s or more to be visible in other locations. A read right after a write from another region may return the old value or nothing.
- **Write rate.** Cloudflare allows about one write per second to the same key. Faster writes get `429` responses, which are retried with backoff.
- **API rate limit.** The Cloudflare REST API allows 1,200 requests per 5 minutes per user. Once exceeded, calls are blocked for about 5 minutes. Use `many`/`putMany` and don't put KV on hot paths that write on every request.
- **Latency.** Each cache call is an HTTPS request to the Cloudflare API, not a local socket. Expect tens of milliseconds, not microseconds. KV is a good fit for data that is read often and written rarely.
- **Sizes.** Keys can be up to 512 bytes. Values can be up to 25 MiB after encoding; the `php` serializer adds about 33% for base64. Oversized keys and values are rejected before any request is made.
- **Keys.** Keys with whitespace, control characters, invalid UTF-8, or more than 512 bytes are stored as `prefix + "~h:" + sha256(key)`. Every other key is stored as `prefix + key`.

## Error handling

Every exception extends `Kopaing\CloudflareKV\Exceptions\CloudflareKVException`. The exception is `CloudflareKVSerializationException`, which extends `InvalidArgumentException`.

| Exception | When |
|---|---|
| `CloudflareKVConfigurationException` | Missing or invalid config, a refused flush, or a bad `lock_store`. |
| `CloudflareKVAuthenticationException` | HTTP 401/403: bad token or missing permission. Not retried. |
| `CloudflareKVRateLimitException` | HTTP 429 after retries, or a `Retry-After` longer than `retry.max_sleep`. Has `->retryAfter`. |
| `CloudflareKVRequestException` | Other API errors, 5xx after retries, or invalid response bodies. Has `->status`, `->operation` and `->errorCodes()`. |
| `CloudflareKVConnectionException` | DNS, TLS, connect or read timeout after retries. |
| `CloudflareKVLimitExceededException` | A key, value or TTL outside Cloudflare's documented limits. |
| `CloudflareKVUnsupportedOperationException` | `increment`/`decrement`/`touch` without opt-in, or `lock` without `lock_store`. |
| `CloudflareKVSerializationException` | The `json` serializer received an object or a non-JSON value. |

**Retries:** connection failures, 408, 429 and 5xx get bounded exponential backoff with jitter. All the REST calls the package makes are idempotent, so retrying them can't apply a change twice. Bulk writes and deletes resend only the keys Cloudflare reports as failed. A `Retry-After` longer than `retry.max_sleep` fails fast instead of blocking a web request for minutes.

**Cache outages:** as with Redis, transport errors are thrown, not swallowed. To degrade gracefully, wrap calls yourself or use Laravel's `failover` cache driver (later Laravel 12 releases and 13).

**A 404 means a miss.** The values endpoint answers 404 both for missing keys and for some namespace misconfigurations. A wrong `namespace_id` therefore shows up as permanent misses on reads and as errors on writes.

### Instrumentation

When `events` is enabled, these events are dispatched. They never include keys, values or credentials:

| Event | Properties |
|---|---|
| `CloudflareKVRequestCompleted` | `operation`, `status`, `durationMs`, `attempts` |
| `CloudflareKVRequestRetrying` | `operation`, `attempt`, `delayMs`, `reason` (`connection`, `http_503`, `partial_failure`, …) |
| `CloudflareKVRequestFailed` | `operation`, `exception` (class), `status`, `durationMs`, `attempts` |
| `CloudflareKVPayloadRejected` | `reason`: `malformed`, `unsupported_version`, `signature` or `encryption` |

```php
Event::listen(CloudflareKVRequestFailed::class, fn ($e) => Log::warning('KV request failed', (array) $e));
```

Laravel's own `CacheHit`, `CacheMissed` and `KeyWritten` events are also dispatched as usual.

## Security recommendations

- **Least privilege:** a token with only *Workers KV Storage: Edit* on one account, ideally with an IP filter and an expiry date.
- **No secrets in logs:** exception messages and events never contain the API token or cached values. The token is also hidden from `dump()` output and stack-trace arguments, and the HTTP client's exceptions (which hold the request headers) are not chained.
- **Safe deserialization:** values are stored in a versioned JSON envelope signed with HMAC-SHA256. The signing key is derived from `APP_KEY` or `signing_key`, and the signature covers the storage key and expiry. `unserialize()` only runs on payloads whose signature checks out, and it respects `serializable_classes`. Anyone who can write to the namespace without your key, such as another app, a Worker, or a dashboard user, can't make your app create PHP objects. Their values are just cache misses.
- **The `json` serializer** never instantiates objects at all. Use it if you only cache arrays and scalars.
- **Encryption:** KV values are readable by anyone with dashboard or API access to the account. If you cache personal or sensitive data, set `encrypt => true` (AES via Laravel's encrypter), or don't cache that data in KV.
- **Key rotation:** changing `APP_KEY` or `signing_key` turns existing entries into misses. They aren't decrypted with the wrong key; they expire or are flushed normally.
- **Isolation:** use one namespace per app and environment, and a unique prefix. Account and namespace IDs are validated to keep requests from escaping the namespace path.

## Testing

```bash
composer test                  # Unit + Feature suites, no network access
composer analyse               # PHPStan (Larastan) at level max
composer format:check          # PHP CS Fixer (PSR-12 based)
```

The Feature suite runs the real client against a stateful in-memory imitation of the KV REST API (`tests/Fakes/FakeCloudflareKVApi.php`) through `Http::fake()`. It covers the HTTP formats, pagination, partial bulk failures, rate limiting, timeouts and expiry.

Live integration tests are opt-in and **must** use a dedicated, empty test namespace:

```bash
CLOUDFLARE_KV_INTEGRATION=true \
CLOUDFLARE_ACCOUNT_ID=... CLOUDFLARE_KV_NAMESPACE_ID=... CLOUDFLARE_API_TOKEN=... \
composer test:integration
```

**Testing your own app:** use the `array` store in tests (`CACHE_STORE=array` in `phpunit.xml`) so your test suite never calls Cloudflare.

## Troubleshooting

| Symptom | Likely cause |
|---|---|
| `Cloudflare KV "account_id" is not configured` | Env variable missing, or config was cached before it was set. Run `php artisan config:clear`. |
| `HTTP 401 [10000] Authentication error` | Wrong or expired token. |
| `HTTP 403` | The token lacks *Workers KV Storage* on that account. |
| Every read is a miss | Wrong `namespace_id`, a rotated `APP_KEY`, or `encrypt` was switched off. Listen for `CloudflareKVPayloadRejected`. |
| Cached models come back as `__PHP_Incomplete_Class` | `cache.serializable_classes` is `false` (the Laravel 13 default). |
| Value changed but old value still returned | Eventual consistency; wait up to ~60 s. |
| `CloudflareKVRateLimitException` | More than 1,200 API calls per 5 minutes, or more than 1 write per second to one key. |
| `increment()` throws | Expected; see [Supported APIs](#supported-and-unsupported-cache-apis). |
| `Refusing to flush ... without a prefix` | Set a prefix, or `flush.allow_without_prefix` on a dedicated namespace. |
| Composer refuses to install on Laravel 11 | Every `laravel/framework` 11.x release is flagged by a Packagist security advisory. Upgrade to Laravel 12 or 13. |

## Version compatibility

| Package | PHP | Laravel | Guzzle | Status |
|---|---|---|---|---|
| 1.x | 8.3, 8.4, 8.5 | 13.0+ | 7.8.2+, 8.x | ✅ Supported |
| 1.x | 8.3, 8.4, 8.5 | 12.0.1+ | 7.8.2+, 8.x | ✅ Supported |
| 1.x | 8.3, 8.4 | 11.33.2+ | 7.8.2+ | ⚠️ Works and is tested. Laravel 11 security support ended in March 2026, and Composer blocks every 11.x release by default because of open advisories. |

CI tests every Laravel and PHP combination above, with both the lowest and the latest allowed dependencies. Verified for this release: Laravel 13.0.0 and 13.34.0, 12.0.1 and 12.69.3, and 11.33.2 and 11.57.0, all on PHP 8.4. PHP 8.3 and 8.5 are covered by the CI matrix.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Please report security issues privately through GitHub Security Advisories, not public issues.

## Publishing and upgrading

### First publication

1. Create an empty GitHub repository `kopaing/laravel-cloudflare-kv`. Keep the name in sync with `homepage`/`support` in `composer.json`.
2. Push the code:
   ```bash
   git remote add origin git@github.com:kopaing/laravel-cloudflare-kv.git
   git push -u origin main
   ```
3. Wait for the **tests** workflow to pass.
4. In `CHANGELOG.md`, rename `## [Unreleased]` to `## [1.0.0] - YYYY-MM-DD`, commit, then tag. The **release** workflow runs the full matrix and creates the GitHub release from that changelog section.
   ```bash
   git tag -a v1.0.0 -m "v1.0.0"
   git push origin v1.0.0
   ```
5. Submit the repository URL at <https://packagist.org/packages/submit>. Packagist reads `composer.json` from GitHub, and its GitHub integration picks up later tags automatically. If it doesn't, add the Packagist webhook under *Settings → Webhooks*.
6. Check the result from a fresh Laravel app:
   ```bash
   composer require kopaing/laravel-cloudflare-kv
   php artisan package:discover   # lists kopaing/laravel-cloudflare-kv
   php artisan tinker --execute="dump(get_class(Cache::store('cloudflare')->getStore()));"
   ```

### Versioning

The package follows [Semantic Versioning](https://semver.org). The public API is the store's behaviour, the config keys, the exception classes, the events and `CloudflareKVClientInterface`. Patch releases contain fixes, minor releases add backwards-compatible features, and major releases contain breaking changes. Before 1.0.0, use pre-release tags like `v1.0.0-beta.1` (the release workflow marks them as pre-releases).

### Upgrading

Read `CHANGELOG.md` before upgrading. The stored payload has a version field (`"v": 1`), so a future format change can still read old entries or treat them as misses. It will never misread them.

## License

MIT. See [LICENSE](LICENSE).
