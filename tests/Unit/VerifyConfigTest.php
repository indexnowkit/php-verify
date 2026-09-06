<?php

declare(strict_types=1);

namespace IndexNowKit\Verify\Tests\Unit;

use IndexNowKit\Config;
use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\Testing\ArrayLogger;
use IndexNowKit\Verify\NonCanonicalPolicy;
use IndexNowKit\Verify\OriginErrorPolicy;
use IndexNowKit\Verify\RedirectPolicy;
use IndexNowKit\Verify\VerifyConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

final class VerifyConfigTest extends TestCase
{
    #[TestDox('defaults: disabled, every policy skip, 5 s timeout, 3 redirects, 100 per batch, robots cached an hour')]
    public function testDefaults(): void
    {
        $config = VerifyConfig::fromArray([]);

        self::assertFalse($config->enabled);
        self::assertSame(RedirectPolicy::Skip, $config->redirect);
        self::assertSame(NonCanonicalPolicy::Skip, $config->nonCanonical);
        self::assertSame(OriginErrorPolicy::Skip, $config->originError);
        self::assertSame(0, $config->delay);
        self::assertSame(5.0, $config->timeout);
        self::assertSame(3, $config->maxRedirects);
        self::assertSame(100, $config->maxBatch);
        self::assertSame(3600, $config->robotsCacheTtl);
        self::assertNull($config->userAgent);
        self::assertMatchesRegularExpression('#^indexnowkit-verify/\d+\.\d+[\w.-]* \(\+https://github\.com/indexnowkit/php\)$#', $config->userAgent());
        self::assertFalse(VerifyConfig::disabled()->enabled);
        self::assertSame(60, $config->timeBudget);
        self::assertCount(11, VerifyConfig::OPTIONS);
        foreach (VerifyConfig::OPTIONS as $option) {
            self::assertStringStartsWith('verify.', $option);
        }
    }

    public function testCoercion(): void
    {
        $config = VerifyConfig::fromArray([
            'enabled' => '1', 'redirect' => 'FOLLOW', 'non_canonical' => 'replace', 'origin_error' => 'send', 'delay' => '5',
            'timeout' => '2.5', 'max_redirects' => '1', 'max_batch' => '10', 'time_budget' => '15', 'robots_cache_ttl' => '0', 'user_agent' => 'my-bot/1',
        ]);

        self::assertTrue($config->enabled);
        self::assertSame(RedirectPolicy::Follow, $config->redirect);
        self::assertSame(NonCanonicalPolicy::Replace, $config->nonCanonical);
        self::assertSame(OriginErrorPolicy::Send, $config->originError);
        self::assertSame(5, $config->delay);
        self::assertSame(2.5, $config->timeout);
        self::assertSame(1, $config->maxRedirects);
        self::assertSame(10, $config->maxBatch);
        self::assertSame(0, $config->robotsCacheTtl);
        self::assertSame('my-bot/1', $config->userAgent());
        self::assertSame(['enabled' => true, 'redirect' => 'follow', 'non_canonical' => 'replace', 'origin_error' => 'send', 'delay' => 5, 'timeout' => 2.5, 'max_redirects' => 1, 'max_batch' => 10, 'time_budget' => 15, 'robots_cache_ttl' => 0, 'user_agent' => 'my-bot/1'], $config->toArray());
        self::assertSame(array_keys(VerifyConfig::fromArray([])->toArray()), array_map(static fn(string $o): string => substr($o, 7), VerifyConfig::OPTIONS), 'toArray() and OPTIONS name the same keys');
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalid(): iterable
    {
        yield 'redirect' => [['redirect' => 'bounce'], '"verify.redirect" must be one of skip, follow, got "bounce".'];
        yield 'non_canonical' => [['non_canonical' => true], '"verify.non_canonical" must be one of skip, replace, got "1".'];
        yield 'origin_error' => [['origin_error' => 'retry'], '"verify.origin_error" must be one of skip, send, got "retry".'];
        yield 'delay too long' => [['delay' => 31], '"verify.delay" must be between 0 and 30 seconds, got 31.'];
        yield 'delay negative' => [['delay' => -1], '"verify.delay" must be between 0 and 30 seconds, got -1.'];
        yield 'delay not an integer' => [['delay' => '1.5'], '"verify.delay" must be an integer, got "1.5".'];
        yield 'timeout' => [['timeout' => 0], '"verify.timeout" must be > 0 seconds, got 0.'];
        yield 'timeout text' => [['timeout' => 'fast'], '"verify.timeout" must be a number, got "fast".'];
        yield 'max_redirects' => [['max_redirects' => -1], '"verify.max_redirects" must be >= 0, got -1.'];
        yield 'max_batch' => [['max_batch' => 0], '"verify.max_batch" must be >= 1, got 0.'];
        yield 'robots_cache_ttl' => [['robots_cache_ttl' => -5], '"verify.robots_cache_ttl" must be >= 0, got -5.'];
        yield 'enabled' => [['enabled' => 'maybe'], '"verify.enabled" must be a boolean, got "maybe".'];
        yield 'user_agent with a newline' => [['user_agent' => "a\nb"], '"verify.user_agent" must be a non-empty single line or null.'];
    }

    /**
     * @param array<string, mixed> $block
     */
    #[DataProvider('invalid')]
    public function testInvalid(array $block, string $message): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage($message);
        VerifyConfig::fromArray($block);
    }

    #[TestDox('loadOrDisabled(): an invalid block is one critical line naming the check command, and verify is off')]
    public function testLoadOrDisabled(): void
    {
        $logger = new ArrayLogger();
        $config = VerifyConfig::loadOrDisabled(['max_batch' => 'many'], $logger, 'php artisan indexnow:check');

        self::assertFalse($config->enabled);
        self::assertCount(1, $logger->messages('critical'));
        self::assertStringContainsString('"verify.max_batch" must be an integer, got "many". (run "php artisan indexnow:check")', $logger->messages('critical')[0]);
        self::assertTrue(VerifyConfig::loadOrDisabled(['enabled' => true], $logger, 'x')->enabled);
    }

    #[TestDox('transportConfig(): the core configuration with http.timeout = verify.timeout, everything else as is')]
    public function testTransportConfig(): void
    {
        $core = Config::fromArray(['key' => 'abcdef1234567890abcdef1234567890', 'base_url' => 'https://www.example.com', 'http' => ['timeout' => 10, 'client' => 'app.client']]);
        $transport = VerifyConfig::fromArray(['timeout' => 2])->transportConfig($core);

        self::assertSame(2.0, $transport->httpTimeout);
        self::assertSame('app.client', $transport->httpClient);
        self::assertSame($core->key, $transport->key);
    }
}
