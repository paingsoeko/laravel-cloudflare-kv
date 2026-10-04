# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `cloudflare` cache driver registered through `Cache::extend()` with Laravel package discovery.
- `CloudflareKVStore` implementing `Illuminate\Contracts\Cache\Store` (including Laravel 13's `touch()`):
  `get`, `many`, `put`, `putMany`, `forever`, `forget`, `flush`, `getPrefix`.
- Cloudflare Workers KV REST client using the single-value, bulk get (100 keys), bulk write
  (10,000 pairs / 100 MB), bulk delete (10,000 keys) and paginated list endpoints.
- Logical expiration stored in every payload; Cloudflare-side TTL is `max(ttl, 60)` so short TTLs
  are honoured exactly without premature deletion.
- Versioned, HMAC-signed payload envelope; `php` (signed `serialize`) and `json` serializers;
  `serializable_classes` support; optional encryption with Laravel's encrypter.
- Bounded exponential backoff with jitter for connection errors, 408, 429 and 5xx, honouring
  `Retry-After` up to a cap; partial bulk failures retry only the failed keys.
- Exception hierarchy: configuration, authentication, rate limit, request, connection,
  limit exceeded, unsupported operation and serialization exceptions.
- Prefix-scoped, paginated `flush()` with protection against flushing an unprefixed namespace and
  a `flush.enabled` kill switch.
- Opt-in, explicitly non-atomic `increment`/`decrement`/`touch` (`allow_non_atomic_updates`).
- Lock delegation to a lock-capable store via `lock_store`.
- Instrumentation events without keys, values or credentials.
- Publishable `config/cloudflare-kv.php` with documented precedence rules.
- Unit, feature (HTTP-faked) and opt-in live integration test suites; GitHub Actions CI matrix and
  tag-driven release workflow.

[Unreleased]: https://github.com/kopaing/laravel-cloudflare-kv/commits/main
