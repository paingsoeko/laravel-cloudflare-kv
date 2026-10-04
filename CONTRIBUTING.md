# Contributing

Thanks for helping improve `kopaing/laravel-cloudflare-kv`.

## Ground rules

- **Honest semantics first.** Cloudflare Workers KV is eventually consistent and has no atomic
  primitives. Changes must not present a non-atomic or best-effort behaviour as if it were
  Redis-equivalent. If something cannot be done safely, reject it with a clear exception and document it.
- **Never log or expose** API tokens, signing keys or cached values (exceptions, events, debug output).
- **Verify against the official Cloudflare documentation** when touching request formats or limits,
  and cite the page in the code comment.

## Development setup

```bash
git clone git@github.com:kopaing/laravel-cloudflare-kv.git
cd laravel-cloudflare-kv
composer install
```

## Before opening a pull request

```bash
composer test           # Unit + Feature suites (no network)
composer analyse        # PHPStan / Larastan, level max
composer format         # PHP CS Fixer (PSR-12 based)
```

- Add or update tests for every behaviour change. HTTP behaviour is tested through
  `tests/Fakes/FakeCloudflareKVApi.php`; extend it rather than stubbing the client.
- Update `README.md` for user-facing changes and add an entry under `## [Unreleased]` in `CHANGELOG.md`.
- Keep the support matrix accurate: CI must pass for every declared PHP and Laravel version,
  with both lowest and latest dependencies.

### Live integration tests (optional)

Use a dedicated, empty namespace and a token limited to *Workers KV Storage*:

```bash
CLOUDFLARE_KV_INTEGRATION=true \
CLOUDFLARE_ACCOUNT_ID=... CLOUDFLARE_KV_NAMESPACE_ID=... CLOUDFLARE_API_TOKEN=... \
composer test:integration
```

## Reporting security issues

Please do not open a public issue. Use GitHub's private vulnerability reporting
(*Security → Report a vulnerability*) on the repository.

## Releases (maintainers)

1. Move `## [Unreleased]` entries into `## [X.Y.Z] - YYYY-MM-DD` and add a fresh `## [Unreleased]`.
2. Commit, then `git tag -a vX.Y.Z -m "vX.Y.Z" && git push origin vX.Y.Z`.
3. The `release` workflow runs the full test matrix and publishes the GitHub release from the changelog
   section. Packagist updates automatically from the tag.
