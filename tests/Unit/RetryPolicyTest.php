<?php

declare(strict_types=1);

namespace Kopaing\CloudflareKV\Tests\Unit;

use Kopaing\CloudflareKV\Support\RetryPolicy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RetryPolicyTest extends TestCase
{
    #[Test]
    public function only_transient_statuses_are_retryable(): void
    {
        foreach ([408, 429, 500, 502, 503, 504, 520] as $status) {
            $this->assertTrue(RetryPolicy::isRetryableStatus($status), (string) $status);
        }

        foreach ([200, 400, 401, 403, 404, 409, 413] as $status) {
            $this->assertFalse(RetryPolicy::isRetryableStatus($status), (string) $status);
        }
    }

    #[Test]
    public function attempts_are_bounded_and_require_idempotency(): void
    {
        $policy = new RetryPolicy(maxAttempts: 3);

        $this->assertTrue($policy->canRetry(1, idempotent: true));
        $this->assertTrue($policy->canRetry(2, idempotent: true));
        $this->assertFalse($policy->canRetry(3, idempotent: true));
        $this->assertFalse($policy->canRetry(1, idempotent: false));
        $this->assertFalse((new RetryPolicy(maxAttempts: 1))->canRetry(1, idempotent: true));
    }

    #[Test]
    public function delays_grow_exponentially_up_to_the_cap(): void
    {
        $policy = new RetryPolicy(maxAttempts: 10, baseDelayMs: 100, maxDelayMs: 500, jitter: false);

        $this->assertSame([100, 200, 400, 500, 500], array_map($policy->delayFor(...), [1, 2, 3, 4, 5]));
    }

    #[Test]
    public function jitter_keeps_delays_within_half_and_full_backoff(): void
    {
        $policy = new RetryPolicy(baseDelayMs: 400, maxDelayMs: 10_000, random: static fn (int $min, int $max): int => $max);
        $this->assertSame(800, $policy->delayFor(2));

        $policy = new RetryPolicy(baseDelayMs: 400, maxDelayMs: 10_000, random: static fn (int $min, int $max): int => $min);
        $this->assertSame(400, $policy->delayFor(2));

        $policy = new RetryPolicy(baseDelayMs: 400, maxDelayMs: 10_000);
        for ($i = 0; $i < 50; $i++) {
            $delay = $policy->delayFor(3);
            $this->assertGreaterThanOrEqual(800, $delay);
            $this->assertLessThanOrEqual(1600, $delay);
        }
    }

    #[Test]
    public function retry_after_is_honoured_only_within_the_cap(): void
    {
        $policy = new RetryPolicy(maxDelayMs: 2000);

        $this->assertSame(2000, $policy->delayFor(1, retryAfterSeconds: 2));
        $this->assertSame(0, $policy->delayFor(1, retryAfterSeconds: 0));
        $this->assertNull($policy->delayFor(1, retryAfterSeconds: 300));
    }

    #[Test]
    public function it_parses_retry_after_headers(): void
    {
        $now = 1_700_000_000;

        $this->assertSame(30, RetryPolicy::parseRetryAfter('30'));
        $this->assertSame(30, RetryPolicy::parseRetryAfter(' 30 '));
        $this->assertSame(120, RetryPolicy::parseRetryAfter(gmdate('D, d M Y H:i:s', $now + 120).' GMT', $now));
        $this->assertSame(0, RetryPolicy::parseRetryAfter(gmdate('D, d M Y H:i:s', $now - 10).' GMT', $now));
        $this->assertNull(RetryPolicy::parseRetryAfter(null));
        $this->assertNull(RetryPolicy::parseRetryAfter(''));
        $this->assertNull(RetryPolicy::parseRetryAfter('soon'));
    }

    #[Test]
    public function it_sleeps_through_the_injected_sleeper(): void
    {
        $slept = [];
        $policy = new RetryPolicy(sleeper: function (int $ms) use (&$slept): void {
            $slept[] = $ms;
        });

        $policy->sleep(250);
        $policy->sleep(0);

        $this->assertSame([250], $slept);
    }
}
