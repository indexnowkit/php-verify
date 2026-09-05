<?php

declare(strict_types=1);

namespace IndexNowKit\Verify\Tests\Support;

use Closure;
use IndexNowKit\Client;
use IndexNowKit\Config;
use IndexNowKit\Debounce\MemoryDebounceStore;
use IndexNowKit\Key\StaticKeyProvider;
use IndexNowKit\Submitter;
use IndexNowKit\Testing\ArrayLogger;
use IndexNowKit\Testing\FakeTransport;
use IndexNowKit\Throttle\NullThrottle;
use IndexNowKit\Url\UrlNormalizerFactory;
use IndexNowKit\Verify\RobotsCache;
use IndexNowKit\Verify\VerifyConfig;
use IndexNowKit\Verify\VerifyingSubmitter;
use Psr\SimpleCache\CacheInterface;

final class Factory
{
    public const KEY = 'abcdef1234567890abcdef1234567890';
    public const HOST = 'www.example.com';

    /**
     * @param array<string, mixed> $overrides
     */
    public static function config(array $overrides = []): Config
    {
        return Config::fromArray($overrides + ['key' => self::KEY, 'base_url' => 'https://www.example.com', 'debounce' => ['per_url' => 0], 'engines' => ['api']]);
    }

    /**
     * The real core submitter over $transport (POSTs answered 200 by default) and the decorator over it, sharing
     * the transport for the pre-flight GETs.
     *
     * @param array<string, mixed> $verify    the raw `verify` block (`enabled: true` unless overridden)
     * @param array<string, mixed> $overrides core configuration overrides
     *
     * @return array{0: VerifyingSubmitter, 1: Submitter}
     */
    public static function submitters(FakeTransport $transport, array $verify = [], array $overrides = [], ?ArrayLogger $logger = null, ?RecordingEvents $events = null, ?RecordingStore $store = null, ?CacheInterface $robotsCache = null, bool $inWebRequest = false, ?Closure $sleep = null): array
    {
        $logger ??= new ArrayLogger();
        $config = self::config($overrides);
        $verifyConfig = VerifyConfig::fromArray($verify + ['enabled' => true]);
        $keys = StaticKeyProvider::fromConfig($config);
        $normalizer = UrlNormalizerFactory::fromConfig($config);
        $inner = new Submitter(new Client($transport, $keys, $config, $logger, new NullThrottle(), $normalizer), $config, new MemoryDebounceStore(), $logger, $normalizer, $events, $store);
        $robots = new RobotsCache($transport, $robotsCache, $config->debounceKeyPrefix, $verifyConfig->robotsCacheTtl, $logger);

        return [new VerifyingSubmitter($inner, $transport, $verifyConfig, $keys, $normalizer, $logger, $events, $store, $robots, null, $inWebRequest, $sleep), $inner];
    }

    /** A transport whose robots.txt answers 404 (no robots.txt), the usual site. */
    public static function transport(): FakeTransport
    {
        return new FakeTransport();
    }
}
