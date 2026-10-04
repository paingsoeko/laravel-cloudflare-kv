<?php

declare(strict_types=1);

namespace Kopaing\CloudflareKV\Support;

use Illuminate\Contracts\Encryption\StringEncrypter;
use JsonException;
use Kopaing\CloudflareKV\Exceptions\CloudflareKVConfigurationException;
use Kopaing\CloudflareKV\Exceptions\CloudflareKVInvalidPayloadException;
use Kopaing\CloudflareKV\Exceptions\CloudflareKVSerializationException;
use SensitiveParameter;
use Throwable;

/**
 * Encodes cache values into a versioned, signed JSON envelope and decodes them back.
 *
 * Envelope (format version 1):
 *
 *     {"v":1,"f":"php|json","x":0|1,"e":<unix expiry|null>,"d":"<data>","s":"<hmac>"}
 *
 *  - "f" is the data format. "php" is base64(serialize($value)), which round-trips any
 *    serializable value (Eloquent models, collections, DTOs). "json" is json_encode($value)
 *    and only accepts null, scalars and arrays of those.
 *  - "x" marks data encrypted with Laravel's Encrypter.
 *  - "e" is the logical expiry. It is authoritative: an expired entry is never returned,
 *    even while Cloudflare still retains it.
 *  - "s" is an HMAC-SHA256 over version, format, encryption flag, expiry, storage key and
 *    data. php-format payloads are only ever unserialize()d after this signature verifies,
 *    so a value written to KV by anyone without the signing key is rejected, and a value
 *    copied from one key to another fails verification.
 */
final class ValueSerializer
{
    public const VERSION = 1;

    public const FORMAT_PHP = 'php';

    public const FORMAT_JSON = 'json';

    private const JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

    private readonly ?string $signingKey;

    /**
     * @param  array<int, class-string>|bool  $allowedClasses  Passed to unserialize()'s "allowed_classes".
     */
    public function __construct(
        private readonly string $format,
        #[SensitiveParameter] ?string $signingKey,
        private readonly array|bool $allowedClasses = true,
        private readonly ?StringEncrypter $encrypter = null,
    ) {
        if (! in_array($format, [self::FORMAT_PHP, self::FORMAT_JSON], true)) {
            throw new CloudflareKVConfigurationException(
                'The Cloudflare KV serializer must be "php" or "json".',
            );
        }

        if ($format === self::FORMAT_PHP && ($signingKey === null || $signingKey === '')) {
            throw new CloudflareKVConfigurationException(
                'The "php" serializer requires a signing key so that only trusted payloads are unserialized. '
                .'Set APP_KEY, set the store\'s "signing_key", or use the "json" serializer.',
            );
        }

        $this->signingKey = ($signingKey === null || $signingKey === '') ? null : self::deriveKey($signingKey);
    }

    public function format(): string
    {
        return $this->format;
    }

    public function encode(mixed $value, ?int $expiresAt, string $storageKey): string
    {
        $data = $this->format === self::FORMAT_PHP
            ? base64_encode(serialize($value))
            : $this->encodeJsonData($value);

        $encrypted = $this->encrypter !== null;

        if ($encrypted) {
            $data = $this->encrypter->encryptString($data);
        }

        $envelope = [
            'v' => self::VERSION,
            'f' => $this->format,
            'x' => $encrypted ? 1 : 0,
            'e' => $expiresAt,
            'd' => $data,
        ];

        if ($this->signingKey !== null) {
            $envelope['s'] = $this->sign($this->format, $encrypted, $expiresAt, $storageKey, $data);
        }

        return json_encode($envelope, self::JSON_FLAGS);
    }

    /**
     * @throws CloudflareKVInvalidPayloadException when the raw value is not an authentic payload.
     */
    public function decode(string $raw, string $storageKey): Payload
    {
        try {
            $envelope = json_decode($raw, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new CloudflareKVInvalidPayloadException('malformed');
        }

        if (! is_array($envelope) || ! isset($envelope['v'], $envelope['f'], $envelope['d'])) {
            throw new CloudflareKVInvalidPayloadException('malformed');
        }

        if ($envelope['v'] !== self::VERSION) {
            throw new CloudflareKVInvalidPayloadException('unsupported_version');
        }

        $format = $envelope['f'];
        $data = $envelope['d'];
        $encrypted = ($envelope['x'] ?? 0) === 1;
        $expiresAt = $envelope['e'] ?? null;

        if (! in_array($format, [self::FORMAT_PHP, self::FORMAT_JSON], true)
            || ! is_string($data)
            || ($expiresAt !== null && ! is_int($expiresAt))) {
            throw new CloudflareKVInvalidPayloadException('malformed');
        }

        if ($format === self::FORMAT_PHP && $this->signingKey === null) {
            throw new CloudflareKVInvalidPayloadException('signature');
        }

        if ($this->signingKey !== null) {
            $signature = $envelope['s'] ?? null;
            $expected = $this->sign($format, $encrypted, $expiresAt, $storageKey, $data);

            if (! is_string($signature) || ! hash_equals($expected, $signature)) {
                throw new CloudflareKVInvalidPayloadException('signature');
            }
        }

        if ($encrypted) {
            if ($this->encrypter === null) {
                throw new CloudflareKVInvalidPayloadException('encryption');
            }

            try {
                $data = $this->encrypter->decryptString($data);
            } catch (Throwable) {
                throw new CloudflareKVInvalidPayloadException('encryption');
            }
        }

        $value = $format === self::FORMAT_PHP
            ? $this->unserialize($data)
            : $this->decodeJsonData($data);

        return new Payload($value, $expiresAt);
    }

    private function encodeJsonData(mixed $value): string
    {
        self::assertJsonSafe($value);

        try {
            return json_encode($value, self::JSON_FLAGS);
        } catch (JsonException $e) {
            throw new CloudflareKVSerializationException(
                'The value cannot be stored with the "json" serializer: '.$e->getMessage(),
                previous: $e,
            );
        }
    }

    private function decodeJsonData(string $data): mixed
    {
        try {
            return json_decode($data, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new CloudflareKVInvalidPayloadException('malformed');
        }
    }

    private function unserialize(string $data): mixed
    {
        $bytes = base64_decode($data, true);

        if ($bytes === false) {
            throw new CloudflareKVInvalidPayloadException('malformed');
        }

        if ($bytes === serialize(false)) {
            return false;
        }

        // The payload's signature has been verified at this point, so it was produced by
        // this package with the application's key. The error handler only guards against
        // corrupted data turning into PHP warnings.
        set_error_handler(static fn (): bool => true);

        try {
            $value = unserialize($bytes, ['allowed_classes' => $this->allowedClasses]);
        } finally {
            restore_error_handler();
        }

        if ($value === false) {
            throw new CloudflareKVInvalidPayloadException('malformed');
        }

        return $value;
    }

    private function sign(string $format, bool $encrypted, ?int $expiresAt, string $storageKey, string $data): string
    {
        $message = implode("\n", [
            (string) self::VERSION,
            $format,
            $encrypted ? '1' : '0',
            $expiresAt === null ? '' : (string) $expiresAt,
            $storageKey,
            $data,
        ]);

        return hash_hmac('sha256', $message, (string) $this->signingKey);
    }

    private static function deriveKey(string $key): string
    {
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            $key = $decoded === false ? $key : $decoded;
        }

        // Domain-separate the cache signing key from every other use of APP_KEY.
        return hash_hmac('sha256', 'kopaing/laravel-cloudflare-kv:payload-signing:v1', $key, true);
    }

    private static function assertJsonSafe(mixed $value): void
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                self::assertJsonSafe($item);
            }

            return;
        }

        if ($value !== null && ! is_scalar($value)) {
            throw new CloudflareKVSerializationException(sprintf(
                'The "json" serializer only stores null, scalars and arrays; got %s. '
                .'Convert the value to an array first, or use the "php" serializer.',
                get_debug_type($value),
            ));
        }
    }
}
