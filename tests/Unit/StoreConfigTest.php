<?php

declare(strict_types=1);

namespace Kopaing\CloudflareKV\Tests\Unit;

use ArrayObject;
use Kopaing\CloudflareKV\Exceptions\CloudflareKVConfigurationException;
use Kopaing\CloudflareKV\Support\StoreConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StoreConfigTest extends TestCase
{
    private const TOKEN = 'super-SECRET-token';

    /**
     * @return array<string, mixed>
     */
    private static function valid(array $overrides = []): array
    {
        return array_replace([
            'account_id' => '0123456789abcdef0123456789abcdef',
            'namespace_id' => 'fedcba9876543210fedcba9876543210',
            'api_token' => self::TOKEN,
            'prefix' => 'app',
        ], $overrides);
    }

    #[Test]
    public function it_builds_from_a_minimal_configuration_with_safe_defaults(): void
    {
        $config = StoreConfig::fromArray(self::valid());

        $this->assertSame('app:', $config->prefix);
        $this->assertSame(StoreConfig::DEFAULT_BASE_URL, $config->baseUrl);
        $this->assertSame(10.0, $config->timeout);
        $this->assertSame(5.0, $config->connectTimeout);
        $this->assertSame(3, $config->retryTimes);
        $this->assertSame(200, $config->retrySleepMs);
        $this->assertSame('php', $config->serializer);
        $this->assertFalse($config->allowNonAtomicUpdates);
        $this->assertFalse($config->encrypt);
        $this->assertNull($config->foreverTtl);
        $this->assertTrue($config->flushEnabled);
        $this->assertFalse($config->flushWithoutPrefix);
        $this->assertNull($config->lockStore);
    }

    #[Test]
    public function it_reads_nested_and_string_typed_options(): void
    {
        $config = StoreConfig::fromArray(self::valid([
            'timeout' => '2.5',
            'retry' => ['times' => '5', 'sleep' => 50, 'max_sleep' => 1000],
            'flush' => ['enabled' => 'false', 'allow_without_prefix' => true],
            'encrypt' => 'true',
            'forever_ttl' => 86400,
            'serializable_classes' => [ArrayObject::class],
            'lock_store' => 'redis',
        ]));

        $this->assertSame(2.5, $config->timeout);
        $this->assertSame(5, $config->retryTimes);
        $this->assertSame(1000, $config->retryMaxSleepMs);
        $this->assertFalse($config->flushEnabled);
        $this->assertTrue($config->flushWithoutPrefix);
        $this->assertTrue($config->encrypt);
        $this->assertSame(86400, $config->foreverTtl);
        $this->assertSame([ArrayObject::class], $config->serializableClasses);
        $this->assertSame('redis', $config->lockStore);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidConfigurations(): iterable
    {
        yield 'missing account' => [['account_id' => null], 'CLOUDFLARE_ACCOUNT_ID'];
        yield 'blank namespace' => [['namespace_id' => '  '], 'CLOUDFLARE_KV_NAMESPACE_ID'];
        yield 'missing token' => [['api_token' => null], 'CLOUDFLARE_API_TOKEN'];
        yield 'path injection in account' => [['account_id' => '../other'], 'invalid characters'];
        yield 'header injection in token' => [['api_token' => "abc\r\nX-Evil: 1"], 'whitespace'];
        yield 'plain http base url' => [['base_url' => 'http://api.cloudflare.com/client/v4'], 'https://'];
        yield 'zero timeout' => [['timeout' => 0], 'timeout'];
        yield 'too many retries' => [['retry' => ['times' => 50]], 'retry.times'];
        yield 'zero attempts' => [['retry' => ['times' => 0]], 'retry.times'];
        yield 'negative sleep' => [['retry' => ['sleep' => -1]], 'retry.sleep'];
        yield 'short forever ttl' => [['forever_ttl' => 30], 'forever_ttl'];
        yield 'unknown serializer' => [['serializer' => 'msgpack'], 'serializer'];
        yield 'bad serializable classes' => [['serializable_classes' => 'yes'], 'serializable_classes'];
        yield 'retry not array' => [['retry' => 3], 'retry'];
        yield 'bad boolean' => [['encrypt' => 'maybe'], 'encrypt'];
        yield 'prefix with spaces' => [['prefix' => 'my app'], 'prefix'];
    }

    #[Test]
    #[DataProvider('invalidConfigurations')]
    public function it_rejects_invalid_configuration_without_leaking_the_token(array $overrides, string $message): void
    {
        try {
            StoreConfig::fromArray(self::valid($overrides));
            $this->fail('Expected a configuration exception.');
        } catch (CloudflareKVConfigurationException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
            $this->assertStringNotContainsString(self::TOKEN, $e->getMessage());
        }
    }

    #[Test]
    public function debug_output_redacts_secrets(): void
    {
        $config = StoreConfig::fromArray(self::valid(['signing_key' => 'signing-SECRET']));

        $dump = print_r($config->__debugInfo(), true);

        $this->assertStringNotContainsString(self::TOKEN, $dump);
        $this->assertStringNotContainsString('signing-SECRET', $dump);
        $this->assertStringContainsString('[redacted]', $dump);
    }
}
