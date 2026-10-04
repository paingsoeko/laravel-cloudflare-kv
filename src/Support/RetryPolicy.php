<?php

declare(strict_types=1);

namespace Kopaing\CloudflareKV\Support;

use Closure;
use Illuminate\Support\Sleep;

/**
 * Bounded exponential backoff with jitter for transient Cloudflare API failures.
 *
 * Retried: connection failures/timeouts, HTTP 408, 429 and 5xx - and only for operations
 * the caller marks as idempotent. Every KV REST call this package makes is idempotent
 * (GET, overwrite-PUT, DELETE, bulk get/put/delete), so a retried request cannot apply a
 * change twice. Compound read-modify-write sequences are never retried as a whole.
 *
 * A Retry-After hint longer than $maxDelayMs is not waited for: the request fails fast
 * with a rate-limit exception instead of blocking a web request for minutes.
 */
final class RetryPolicy
{
    /** @var Closure(int): void */
    private readonly Closure $sleeper;

    /** @var Closure(int, int): int */
    private readonly Closure $random;

    /**
     * @param  int  $maxAttempts  Total attempts including the first one (Laravel's "retry.times" semantics).
     * @param  int  $baseDelayMs  Delay before the first retry; doubled for each further retry.
     * @param  int  $maxDelayMs  Upper bound for any single delay, including Retry-After.
     * @param  (Closure(int): void)|null  $sleeper  Receives milliseconds; defaults to Laravel's fakeable Sleep.
     * @param  (Closure(int, int): int)|null  $random  Returns an integer in [min, max]; defaults to random_int().
     */
    public function __construct(
        public readonly int $maxAttempts = 3,
        public readonly int $baseDelayMs = 200,
        public readonly int $maxDelayMs = 2000,
        public readonly bool $jitter = true,
        ?Closure $sleeper = null,
        ?Closure $random = null,
    ) {
        $this->sleeper = $sleeper ?? static function (int $milliseconds): void {
            Sleep::usleep($milliseconds * 1000);
        };
        $this->random = $random ?? random_int(...);
    }

    public static function isRetryableStatus(int $status): bool
    {
        return $status === 408 || $status === 429 || $status >= 500;
    }

    public function canRetry(int $attempt, bool $idempotent): bool
    {
        return $idempotent && $attempt < $this->maxAttempts;
    }

    /**
     * Milliseconds to wait before the next attempt, or null when the request must not be retried.
     *
     * @param  int  $attempt  The attempt that just failed (1-based).
     * @param  int|null  $retryAfterSeconds  Parsed Retry-After header, if any.
     */
    public function delayFor(int $attempt, ?int $retryAfterSeconds = null): ?int
    {
        if ($retryAfterSeconds !== null) {
            $requested = $retryAfterSeconds * 1000;

            return $requested > $this->maxDelayMs ? null : $requested;
        }

        $exponential = (int) min($this->maxDelayMs, $this->baseDelayMs * (2 ** max(0, $attempt - 1)));

        if (! $this->jitter || $exponential <= 1) {
            return $exponential;
        }

        // "Equal jitter": keep half of the delay, randomize the other half.
        $half = intdiv($exponential, 2);

        return $half + ($this->random)(0, $exponential - $half);
    }

    public function sleep(int $milliseconds): void
    {
        if ($milliseconds > 0) {
            ($this->sleeper)($milliseconds);
        }
    }

    /**
     * Parse a Retry-After header (delta-seconds or HTTP-date) into whole seconds.
     */
    public static function parseRetryAfter(?string $header, ?int $now = null): ?int
    {
        if ($header === null || trim($header) === '') {
            return null;
        }

        $header = trim($header);

        if (ctype_digit($header)) {
            return (int) $header;
        }

        $timestamp = strtotime($header);

        if ($timestamp === false) {
            return null;
        }

        return max(0, $timestamp - ($now ?? time()));
    }
}
