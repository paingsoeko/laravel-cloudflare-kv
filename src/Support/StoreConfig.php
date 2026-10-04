<?php

declare(strict_types=1);

namespace Kopaing\CloudflareKV\Support;

use Kopaing\CloudflareKV\Exceptions\CloudflareKVConfigurationException;
use SensitiveParameter;

/**
 * Validated, immutable configuration for one Cloudflare KV cache store.
 *
 * Built from the already-merged configuration array (see CloudflareKVManager for the
 * precedence rules). Validation happens before any request is sent, and error messages
 * never echo the API token.
 */
final readonly class StoreConfig
{
    public const DEFAULT_BASE_URL = 'https://api.cloudflare.com/client/v4';

    /**
     * @param  array<int, class-string>|bool  $serializableClasses
     */
    public function __construct(
        public string $accountId,
        public string $namespaceId,
        #[SensitiveParameter] public string $apiToken,
        public string $prefix,
        public string $baseUrl = self::DEFAULT_BASE_URL,
        public float $timeout = 10.0,
        public float $connectTimeout = 5.0,
        public int $retryTimes = 3,
        public int $retrySleepMs = 200,
        public int $retryMaxSleepMs = 2000,
        public string $serializer = ValueSerializer::FORMAT_PHP,
        #[SensitiveParameter] public ?string $signingKey = null,
        public array|bool $serializableClasses = true,
        public bool $encrypt = false,
        public bool $allowNonAtomicUpdates = false,
        public ?int $foreverTtl = null,
        public bool $bulkGet = true,
        public bool $flushEnabled = true,
        public bool $flushWithoutPrefix = false,
        public ?string $lockStore = null,
        public bool $events = true,
    ) {
    }

    /**
     * @param  array<mixed>  $config
     */
    public static function fromArray(array $config): self
    {
        $retry = self::arrayOption($config, 'retry');
        $flush = self::arrayOption($config, 'flush');

        $serializer = self::stringOption($config, 'serializer') ?? ValueSerializer::FORMAT_PHP;

        if (! in_array($serializer, [ValueSerializer::FORMAT_PHP, ValueSerializer::FORMAT_JSON], true)) {
            throw new CloudflareKVConfigurationException('Cloudflare KV "serializer" must be "php" or "json".');
        }

        $baseUrl = rtrim(self::stringOption($config, 'base_url') ?? self::DEFAULT_BASE_URL, '/');

        if (! str_starts_with($baseUrl, 'https://') || filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
            throw new CloudflareKVConfigurationException('Cloudflare KV "base_url" must be a valid https:// URL.');
        }

        $foreverTtl = self::intOption($config, 'forever_ttl');

        if ($foreverTtl !== null && $foreverTtl < 60) {
            throw new CloudflareKVConfigurationException(
                'Cloudflare KV "forever_ttl" must be null or at least 60 seconds (the Cloudflare minimum).',
            );
        }

        $lockStore = self::stringOption($config, 'lock_store');

        return new self(
            accountId: self::identifier($config, 'account_id', 'CLOUDFLARE_ACCOUNT_ID'),
            namespaceId: self::identifier($config, 'namespace_id', 'CLOUDFLARE_KV_NAMESPACE_ID'),
            apiToken: self::token($config),
            prefix: KeyFormatter::normalizePrefix(self::stringOption($config, 'prefix') ?? ''),
            baseUrl: $baseUrl,
            timeout: self::positiveFloat($config, 'timeout', 10.0),
            connectTimeout: self::positiveFloat($config, 'connect_timeout', 5.0),
            retryTimes: self::boundedInt($retry, 'retry.times', 'times', 3, 1, 10),
            retrySleepMs: self::boundedInt($retry, 'retry.sleep', 'sleep', 200, 0, 60_000),
            retryMaxSleepMs: self::boundedInt($retry, 'retry.max_sleep', 'max_sleep', 2000, 0, 60_000),
            serializer: $serializer,
            signingKey: self::stringOption($config, 'signing_key'),
            serializableClasses: self::serializableClasses($config),
            encrypt: self::boolOption($config, 'encrypt', false),
            allowNonAtomicUpdates: self::boolOption($config, 'allow_non_atomic_updates', false),
            foreverTtl: $foreverTtl,
            bulkGet: self::boolOption($config, 'bulk_get', true),
            flushEnabled: self::boolOption($flush, 'enabled', true, 'flush.enabled'),
            flushWithoutPrefix: self::boolOption($flush, 'allow_without_prefix', false, 'flush.allow_without_prefix'),
            lockStore: $lockStore === '' ? null : $lockStore,
            events: self::boolOption($config, 'events', true),
        );
    }

    /**
     * Keep the token out of var_dump()/dump() output.
     *
     * @return array<mixed>
     */
    public function __debugInfo(): array
    {
        $properties = get_object_vars($this);
        $properties['apiToken'] = '[redacted]';
        $properties['signingKey'] = $this->signingKey === null ? null : '[redacted]';

        return $properties;
    }

    /**
     * @param  array<mixed>  $config
     */
    private static function identifier(array $config, string $key, string $env): string
    {
        $value = $config[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new CloudflareKVConfigurationException(sprintf(
                'Cloudflare KV "%s" is not configured. Set %s or the store\'s "%s" option.',
                $key,
                $env,
                $key,
            ));
        }

        // Identifiers are interpolated into the request path, so only allow URL-safe characters.
        if (preg_match('/\A[A-Za-z0-9_-]{1,64}\z/', $value) !== 1) {
            throw new CloudflareKVConfigurationException(sprintf(
                'Cloudflare KV "%s" contains invalid characters.',
                $key,
            ));
        }

        return $value;
    }

    /**
     * @param  array<mixed>  $config
     */
    private static function token(array $config): string
    {
        $value = $config['api_token'] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new CloudflareKVConfigurationException(
                'Cloudflare KV "api_token" is not configured. Set CLOUDFLARE_API_TOKEN or the store\'s "api_token" option.',
            );
        }

        // Reject anything that could break or inject into the Authorization header.
        if (preg_match('/\A[\x21-\x7E]+\z/', $value) !== 1) {
            throw new CloudflareKVConfigurationException(
                'Cloudflare KV "api_token" contains whitespace or non-printable characters.',
            );
        }

        return $value;
    }

    /**
     * @param  array<mixed>  $config
     * @return array<int, class-string>|bool
     */
    private static function serializableClasses(array $config): array|bool
    {
        $value = $config['serializable_classes'] ?? true;

        if (is_bool($value)) {
            return $value;
        }

        if (is_array($value) && array_filter($value, static fn (mixed $class): bool => ! is_string($class)) === []) {
            /** @var array<int, class-string> */
            return array_values($value);
        }

        throw new CloudflareKVConfigurationException(
            'Cloudflare KV "serializable_classes" must be true, false or a list of class names.',
        );
    }

    /**
     * @param  array<mixed>  $config
     * @return array<mixed>
     */
    private static function arrayOption(array $config, string $key): array
    {
        $value = $config[$key] ?? [];

        if (! is_array($value)) {
            throw new CloudflareKVConfigurationException(sprintf('Cloudflare KV "%s" must be an array.', $key));
        }

        return $value;
    }

    /**
     * @param  array<mixed>  $config
     */
    private static function stringOption(array $config, string $key): ?string
    {
        $value = $config[$key] ?? null;

        if ($value !== null && ! is_string($value)) {
            throw new CloudflareKVConfigurationException(sprintf('Cloudflare KV "%s" must be a string.', $key));
        }

        return $value;
    }

    /**
     * @param  array<mixed>  $config
     */
    private static function intOption(array $config, string $key): ?int
    {
        $value = $config[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        if (! is_int($value)) {
            throw new CloudflareKVConfigurationException(sprintf('Cloudflare KV "%s" must be an integer.', $key));
        }

        return $value;
    }

    /**
     * @param  array<mixed>  $config
     */
    private static function boundedInt(array $config, string $name, string $key, int $default, int $min, int $max): int
    {
        $value = self::intOption($config, $key) ?? $default;

        if ($value < $min || $value > $max) {
            throw new CloudflareKVConfigurationException(sprintf(
                'Cloudflare KV "%s" must be between %d and %d.',
                $name,
                $min,
                $max,
            ));
        }

        return $value;
    }

    /**
     * @param  array<mixed>  $config
     */
    private static function positiveFloat(array $config, string $key, float $default): float
    {
        $value = $config[$key] ?? $default;

        if (! is_numeric($value) || (float) $value <= 0) {
            throw new CloudflareKVConfigurationException(sprintf(
                'Cloudflare KV "%s" must be a positive number of seconds.',
                $key,
            ));
        }

        return (float) $value;
    }

    /**
     * @param  array<mixed>  $config
     */
    private static function boolOption(array $config, string $key, bool $default, ?string $name = null): bool
    {
        $value = $config[$key] ?? $default;

        if (is_bool($value)) {
            return $value;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        if ($parsed === null) {
            throw new CloudflareKVConfigurationException(sprintf(
                'Cloudflare KV "%s" must be a boolean.',
                $name ?? $key,
            ));
        }

        return $parsed;
    }
}
