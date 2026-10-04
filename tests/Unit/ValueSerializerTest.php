<?php

declare(strict_types=1);

namespace Kopaing\CloudflareKV\Tests\Unit;

use __PHP_Incomplete_Class;
use ArrayObject;
use Illuminate\Encryption\Encrypter;
use Kopaing\CloudflareKV\Exceptions\CloudflareKVConfigurationException;
use Kopaing\CloudflareKV\Exceptions\CloudflareKVInvalidPayloadException;
use Kopaing\CloudflareKV\Exceptions\CloudflareKVSerializationException;
use Kopaing\CloudflareKV\Support\ValueSerializer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

final class ValueSerializerTest extends TestCase
{
    private const KEY = 'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=';

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function values(): iterable
    {
        yield 'string' => ['hello'];
        yield 'empty string' => [''];
        yield 'int' => [42];
        yield 'zero' => [0];
        yield 'negative' => [-7];
        yield 'float' => [1.5];
        yield 'whole float' => [2.0];
        yield 'true' => [true];
        yield 'false' => [false];
        yield 'list' => [[1, 'two', [3]]];
        yield 'map' => [['status' => 'shipped', 'nested' => ['a' => null]]];
        yield 'unicode' => ['မင်္ဂလာပါ 🌏'];
        yield 'binary-ish string' => ["a\0b\xFF"];
    }

    #[Test]
    #[DataProvider('values')]
    public function php_format_round_trips_values(mixed $value): void
    {
        $serializer = new ValueSerializer('php', self::KEY);

        $this->assertSame($value, $serializer->decode($serializer->encode($value, null, 'k'), 'k')->value);
    }

    #[Test]
    public function php_format_round_trips_objects(): void
    {
        $serializer = new ValueSerializer('php', self::KEY);
        $object = new ArrayObject(['a' => 1]);

        $decoded = $serializer->decode($serializer->encode($object, null, 'k'), 'k')->value;

        $this->assertEquals($object, $decoded);
        $this->assertInstanceOf(ArrayObject::class, $decoded);
    }

    #[Test]
    public function json_format_round_trips_json_safe_values(): void
    {
        $serializer = new ValueSerializer('json', self::KEY);

        foreach (self::values() as [$value]) {
            if (is_string($value) && ! mb_check_encoding($value, 'UTF-8')) {
                continue;
            }

            $this->assertSame($value, $serializer->decode($serializer->encode($value, null, 'k'), 'k')->value);
        }
    }

    #[Test]
    public function json_format_rejects_objects_even_when_nested(): void
    {
        $serializer = new ValueSerializer('json', null);

        $this->expectException(CloudflareKVSerializationException::class);
        $this->expectExceptionMessage('stdClass');

        $serializer->encode(['user' => new stdClass()], null, 'k');
    }

    #[Test]
    public function json_format_rejects_values_json_cannot_represent(): void
    {
        $this->expectException(CloudflareKVSerializationException::class);

        (new ValueSerializer('json', null))->encode(INF, null, 'k');
    }

    #[Test]
    public function the_envelope_is_versioned_and_carries_the_expiration(): void
    {
        $serializer = new ValueSerializer('json', self::KEY);
        $raw = $serializer->encode(['a' => 1], 1_900_000_000, 'k');
        $envelope = json_decode($raw, true);

        $this->assertSame(1, $envelope['v']);
        $this->assertSame('json', $envelope['f']);
        $this->assertSame(1_900_000_000, $envelope['e']);
        $this->assertSame('{"a":1}', $envelope['d']);
        $this->assertSame(64, strlen($envelope['s']));

        $payload = $serializer->decode($raw, 'k');
        $this->assertSame(1_900_000_000, $payload->expiresAt);
        $this->assertFalse($payload->isExpired(1_899_999_999));
        $this->assertTrue($payload->isExpired(1_900_000_000));
        $this->assertSame(10, $payload->remainingSeconds(1_899_999_990));
    }

    #[Test]
    public function the_php_format_requires_a_signing_key(): void
    {
        $this->expectException(CloudflareKVConfigurationException::class);

        new ValueSerializer('php', null);
    }

    #[Test]
    public function it_rejects_unknown_formats(): void
    {
        $this->expectException(CloudflareKVConfigurationException::class);

        new ValueSerializer('igbinary', self::KEY);
    }

    #[Test]
    public function tampered_data_is_rejected_before_unserialize(): void
    {
        $serializer = new ValueSerializer('php', self::KEY);
        $envelope = json_decode($serializer->encode('safe', null, 'k'), true);
        $envelope['d'] = base64_encode(serialize(new ArrayObject(['evil' => true])));

        $this->assertRejected($serializer, json_encode($envelope), 'k', 'signature');
    }

    #[Test]
    public function a_forged_unsigned_php_payload_is_rejected(): void
    {
        $forged = json_encode(['v' => 1, 'f' => 'php', 'x' => 0, 'e' => null, 'd' => base64_encode(serialize('x'))]);

        $this->assertRejected(new ValueSerializer('php', self::KEY), $forged, 'k', 'signature');
        // A json-only reader without a key must not unserialize php payloads either.
        $this->assertRejected(new ValueSerializer('json', null), $forged, 'k', 'signature');
    }

    #[Test]
    public function the_signature_binds_the_payload_to_its_key_and_expiration(): void
    {
        $serializer = new ValueSerializer('php', self::KEY);
        $raw = $serializer->encode('value', 1_900_000_000, 'app:a');

        $this->assertRejected($serializer, $raw, 'app:b', 'signature');

        $envelope = json_decode($raw, true);
        $envelope['e'] = null;
        $this->assertRejected($serializer, json_encode($envelope), 'app:a', 'signature');
    }

    #[Test]
    public function a_different_signing_key_cannot_read_entries(): void
    {
        $raw = (new ValueSerializer('php', self::KEY))->encode('value', null, 'k');

        $this->assertRejected(new ValueSerializer('php', 'another-key'), $raw, 'k', 'signature');
    }

    #[Test]
    public function malformed_and_future_payloads_are_rejected(): void
    {
        $serializer = new ValueSerializer('php', self::KEY);

        $this->assertRejected($serializer, 'not json', 'k', 'malformed');
        $this->assertRejected($serializer, '"a string"', 'k', 'malformed');
        $this->assertRejected($serializer, '{"v":1,"f":"php"}', 'k', 'malformed');
        $this->assertRejected($serializer, '{"v":1,"f":"php","e":"soon","d":"x"}', 'k', 'malformed');
        $this->assertRejected($serializer, '{"v":2,"f":"php","e":null,"d":"x"}', 'k', 'unsupported_version');
    }

    #[Test]
    public function allowed_classes_restrict_what_unserialize_may_instantiate(): void
    {
        $writer = new ValueSerializer('php', self::KEY);
        $reader = new ValueSerializer('php', self::KEY, allowedClasses: false);

        $value = $reader->decode($writer->encode(new ArrayObject([1]), null, 'k'), 'k')->value;

        $this->assertInstanceOf(__PHP_Incomplete_Class::class, $value);
        $this->assertSame(['a' => 1], $reader->decode($writer->encode(['a' => 1], null, 'k'), 'k')->value);
    }

    #[Test]
    public function encrypted_payloads_hide_the_plaintext_and_round_trip(): void
    {
        $encrypter = new Encrypter(str_repeat('e', 32), 'AES-256-CBC');
        $serializer = new ValueSerializer('json', self::KEY, encrypter: $encrypter);

        $raw = $serializer->encode(['card' => '4242-secret'], null, 'k');

        $this->assertStringNotContainsString('4242-secret', $raw);
        $this->assertSame(1, json_decode($raw, true)['x']);
        $this->assertSame(['card' => '4242-secret'], $serializer->decode($raw, 'k')->value);

        $this->assertRejected(new ValueSerializer('json', self::KEY), $raw, 'k', 'encryption');
    }

    #[Test]
    public function json_without_a_signing_key_reads_unsigned_payloads(): void
    {
        $serializer = new ValueSerializer('json', null);
        $raw = $serializer->encode([1, 2], null, 'k');

        $this->assertArrayNotHasKey('s', json_decode($raw, true));
        $this->assertSame([1, 2], $serializer->decode($raw, 'k')->value);

        // Once a key is configured, unsigned payloads are no longer trusted.
        $this->assertRejected(new ValueSerializer('json', self::KEY), $raw, 'k', 'signature');
    }

    private function assertRejected(ValueSerializer $serializer, string $raw, string $key, string $reason): void
    {
        try {
            $serializer->decode($raw, $key);
            $this->fail('Expected the payload to be rejected.');
        } catch (CloudflareKVInvalidPayloadException $e) {
            $this->assertSame($reason, $e->getMessage());
        }
    }
}
