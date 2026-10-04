<?php

declare(strict_types=1);

namespace Kopaing\CloudflareKV\Tests\Unit;

use Kopaing\CloudflareKV\Exceptions\CloudflareKVConfigurationException;
use Kopaing\CloudflareKV\Support\KeyFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class KeyFormatterTest extends TestCase
{
    #[Test]
    public function it_prefixes_keys_verbatim_when_they_are_valid_kv_names(): void
    {
        $keys = new KeyFormatter('app:');

        $this->assertSame('app:user:1', $keys->format('user:1'));
        $this->assertSame('app:tracking/ORDER-1001?x=%20', $keys->format('tracking/ORDER-1001?x=%20'));
        $this->assertSame('app:ユーザー:1', $keys->format('ユーザー:1'));
    }

    #[Test]
    public function it_appends_a_delimiter_to_prefixes_ending_in_a_letter_or_digit(): void
    {
        $this->assertSame('app:', (new KeyFormatter('app'))->prefix());
        $this->assertSame('app2:', (new KeyFormatter('app2'))->prefix());
        $this->assertSame('laravel-cache-', (new KeyFormatter('laravel-cache-'))->prefix());
        $this->assertSame('app:', (new KeyFormatter('app:'))->prefix());
        $this->assertSame('', (new KeyFormatter(''))->prefix());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function keysThatMustBeHashed(): iterable
    {
        yield 'space' => ['user 1'];
        yield 'tab' => ["user\t1"];
        yield 'newline' => ["user\n1"];
        yield 'null byte' => ["user\0"];
        yield 'invalid utf-8' => ["user\xC3\x28"];
        yield 'no-break space' => ["user\u{00A0}1"];
        yield 'too long' => [str_repeat('a', 600)];
        yield 'hash marker' => [KeyFormatter::HASH_MARKER.'abc'];
    }

    #[Test]
    #[DataProvider('keysThatMustBeHashed')]
    public function it_hashes_keys_that_are_not_valid_kv_names(string $key): void
    {
        $formatted = (new KeyFormatter('app:'))->format($key);

        $this->assertSame('app:'.KeyFormatter::HASH_MARKER.hash('sha256', $key), $formatted);
        $this->assertLessThanOrEqual(KeyFormatter::MAX_KEY_BYTES, strlen($formatted));
    }

    #[Test]
    public function it_hashes_dot_segments_and_empty_names_without_a_prefix(): void
    {
        $keys = new KeyFormatter('');

        $this->assertSame(KeyFormatter::HASH_MARKER.hash('sha256', '.'), $keys->format('.'));
        $this->assertSame(KeyFormatter::HASH_MARKER.hash('sha256', '..'), $keys->format('..'));
        $this->assertSame(KeyFormatter::HASH_MARKER.hash('sha256', ''), $keys->format(''));
        $this->assertSame('plain', $keys->format('plain'));
    }

    #[Test]
    public function it_keeps_keys_at_exactly_the_byte_limit_verbatim(): void
    {
        $keys = new KeyFormatter('p:');
        $key = str_repeat('a', KeyFormatter::MAX_KEY_BYTES - 2);

        $this->assertSame('p:'.$key, $keys->format($key));
        $this->assertStringContainsString(KeyFormatter::HASH_MARKER, $keys->format($key.'a'));
    }

    #[Test]
    public function the_mapping_is_deterministic_and_distinct(): void
    {
        $keys = new KeyFormatter('p:');

        $this->assertSame($keys->format('a b'), $keys->format('a b'));
        $this->assertNotSame($keys->format('a b'), $keys->format('a  b'));
    }

    #[Test]
    public function it_rejects_invalid_prefixes(): void
    {
        $this->expectException(CloudflareKVConfigurationException::class);

        new KeyFormatter('my app');
    }

    #[Test]
    public function it_rejects_prefixes_that_leave_no_room_for_keys(): void
    {
        $this->expectException(CloudflareKVConfigurationException::class);

        new KeyFormatter(str_repeat('a', KeyFormatter::MAX_PREFIX_BYTES + 1));
    }
}
