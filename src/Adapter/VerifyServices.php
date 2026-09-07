<?php

declare(strict_types=1);

namespace IndexNowKit\Verify\Adapter;

use Closure;
use IndexNowKit\Adapter\OptionalPackage;
use IndexNowKit\Adapter\Services;
use IndexNowKit\Adapter\SubmitterFactoryInterface;
use IndexNowKit\Check\CheckInterface;
use IndexNowKit\Check\CheckLevel;
use IndexNowKit\Check\StaticCheck;
use IndexNowKit\Config;
use IndexNowKit\Http\TransportFactory;
use IndexNowKit\Http\TransportInterface;
use IndexNowKit\Key\KeyProviderInterface;
use IndexNowKit\Submission\SubmissionStoreInterface;
use IndexNowKit\SubmitterInterface;
use IndexNowKit\Url\UrlNormalizerInterface;
use IndexNowKit\Verify\Check\DispatchCheck;
use IndexNowKit\Verify\Check\SampleCheck;
use IndexNowKit\Verify\Check\TransportCheck;
use IndexNowKit\Verify\RobotsCache;
use IndexNowKit\Verify\VerifyConfig;
use IndexNowKit\Verify\VerifyingSubmitter;
use IndexNowKit\Verify\VerifyingSubmitterFactory;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * What every framework adapter wires for this package, in one place: the predicate, the owned options, the validated
 * block, the pre-flight transport, the robots cache, the decorated submitter and command submitter factory, and the
 * `check` lines with their texts. The `*For()` methods take the core's runtime graph (`Adapter\Services`: Yii, plain
 * PHP); the others take the pieces one by one, for a container that binds them itself (Laravel, Symfony).
 *
 * An adapter keeps only what its framework decides: where the block comes from, how `http.client` and the cache are
 * looked up, what its sample check reads (`SampleCheck` is built through {@see sampleCheck()}), and when a request is
 * a web request (`$inWebRequest`).
 */
final class VerifyServices
{
    /**
     * The one predicate for `indexnowkit/verify`: `OptionalPackage::verify()` of the core, which an adapter calls
     * directly — this class lives in the package and cannot be loaded to say "not installed". null = detect, false =
     * wire as if the package were absent (tests).
     */
    public static function package(?bool $installed = null): OptionalPackage
    {
        return OptionalPackage::verify($installed);
    }

    /**
     * The dotted keys of the `verify` block, for the adapter's `ConfigFactory` (`VerifyConfig::OPTIONS`).
     *
     * @return list<string>
     */
    public static function options(): array
    {
        return VerifyConfig::OPTIONS;
    }

    /**
     * The validated `verify` block; a broken value switches the pre-flight off with a critical log line naming
     * $checkCommand (`php artisan indexnow:check`, `php yii indexnow/check`).
     *
     * @param array<string, mixed> $block
     */
    public static function config(array $block, LoggerInterface $logger, string $checkCommand): VerifyConfig
    {
        return VerifyConfig::loadOrDisabled($block, $logger, $checkCommand);
    }

    /**
     * The pre-flight transport: `verify.timeout`, `verify.user_agent` and the body limit over the PSR-18 client the
     * core discovers, with `max_redirects: 0`. The application's `http.client` is **not** used here and $clientLocator
     * is ignored (kept so the adapters keep compiling): the pre-flight must see the 3xx answers itself, and a client
     * the application configured may follow them silently ({@see VerifyConfig::transportConfig()}). `http.client`
     * still carries the POST submissions of the inner submitter.
     *
     * @param (Closure(string): mixed)|null $clientLocator ignored
     */
    public static function transport(VerifyConfig $verify, Config $config, ?Closure $clientLocator = null): TransportInterface
    {
        return TransportFactory::lazy($verify->transportConfig($config), null, ['User-Agent' => $verify->userAgent()], VerifyConfig::BODY_LIMIT);
    }

    /** @param (Closure(string): mixed)|null $clientLocator ignored ({@see transport()}) */
    public static function transportFor(VerifyConfig $verify, Services $services, ?Closure $clientLocator = null): TransportInterface
    {
        return self::transport($verify, $services->config, $clientLocator);
    }

    /** The robots.txt cache of the pre-flight: the PSR-16 cache behind `debounce.store` when there is one, else per process. */
    public static function robots(VerifyConfig $verify, TransportInterface $transport, ?CacheInterface $cache, Config $config, LoggerInterface $logger): RobotsCache
    {
        return new RobotsCache($transport, $cache, $config->debounceKeyPrefix, $verify->robotsCacheTtl, $logger);
    }

    public static function robotsFor(VerifyConfig $verify, Services $services, TransportInterface $transport): RobotsCache
    {
        return self::robots($verify, $transport, $services->failureCache(), $services->config, $services->logger);
    }

    /** The pre-flight decorator around the graph's submitter (sync flushes, queue jobs). */
    public static function submitter(SubmitterInterface $inner, VerifyConfig $verify, TransportInterface $transport, KeyProviderInterface $keys, UrlNormalizerInterface $normalizer, LoggerInterface $logger, ?EventDispatcherInterface $events, ?SubmissionStoreInterface $store, RobotsCache $robots, bool $inWebRequest): SubmitterInterface
    {
        return new VerifyingSubmitter($inner, $transport, $verify, $keys, $normalizer, $logger, $events, $store, $robots, null, $inWebRequest);
    }

    /** @param bool $inWebRequest `dispatch: sync` inside a web request: the delay is skipped, the GETs run in the request */
    public static function submitterFor(SubmitterInterface $inner, VerifyConfig $verify, Services $services, TransportInterface $transport, RobotsCache $robots, bool $inWebRequest): SubmitterInterface
    {
        return self::submitter($inner, $verify, $transport, $services->keys(), $services->normalizer(), $services->logger, $services->events(), $services->submissionStore(), $robots, $inWebRequest);
    }

    /** The pre-flight decorator around the command submitter factory (`--force`, `--dry-run`). */
    public static function submitterFactory(SubmitterFactoryInterface $inner, VerifyConfig $verify, TransportInterface $transport, KeyProviderInterface $keys, UrlNormalizerInterface $normalizer, LoggerInterface $logger, ?EventDispatcherInterface $events, ?SubmissionStoreInterface $store, RobotsCache $robots): SubmitterFactoryInterface
    {
        return new VerifyingSubmitterFactory($inner, $transport, $verify, $keys, $normalizer, $logger, $events, $store, $robots);
    }

    public static function submitterFactoryFor(SubmitterFactoryInterface $inner, VerifyConfig $verify, Services $services, TransportInterface $transport, RobotsCache $robots): SubmitterFactoryInterface
    {
        return self::submitterFactory($inner, $verify, $transport, $services->keys(), $services->normalizer(), $services->logger, $services->events(), $services->submissionStore(), $robots);
    }

    /** The `verify.installed` line of `check`: what the pre-flight does, or that it is off. */
    public static function installedLine(VerifyConfig $verify): string
    {
        return $verify->enabled
            ? \sprintf('verify: enabled (redirect: %s, non_canonical: %s, origin_error: %s)', $verify->redirect->value, $verify->nonCanonical->value, $verify->originError->value)
            : 'verify: installed, disabled (verify.enabled: false)';
    }

    public static function installedCheck(VerifyConfig $verify): StaticCheck
    {
        return new StaticCheck(CheckLevel::Ok, self::installedLine($verify), self::package(true)->checkCode());
    }

    /** @param string $queueWord the framework's word for the asynchronous dispatch the warning recommends (`queue`, `messenger`) */
    public static function dispatchCheck(VerifyConfig $verify, Config $config, string $queueWord): DispatchCheck
    {
        return new DispatchCheck($verify->enabled && $config->dispatch === 'sync', $queueWord);
    }

    public static function transportCheck(VerifyConfig $verify, Config $config): TransportCheck
    {
        return new TransportCheck($verify->enabled, $config->httpClient);
    }

    /**
     * The builder of the package's sample check over the `--sample` / `--sample-class` values of the running `check`
     * command; the adapter's sample check calls it with the URLs and classes it collected.
     *
     * @param (Closure(string, string|null): list<string>)|null $classSampler URLs of a sample of a class (the adapter's ORM sampler)
     *
     * @return Closure(list<string>, list<string>): SampleCheck
     */
    public static function sampleCheck(TransportInterface $transport, VerifyConfig $verify, UrlNormalizerInterface $normalizer, KeyProviderInterface $keys, ?Closure $classSampler, RobotsCache $robots): Closure
    {
        return static function (array $urls, array $classes) use ($transport, $verify, $normalizer, $keys, $classSampler, $robots): SampleCheck {
            /** @var list<string> $urls */
            /** @var list<string> $classes */
            return new SampleCheck($urls, $classes, $transport, $verify, $normalizer, $keys, $classSampler, $robots);
        };
    }

    /**
     * The `check` lines with the package over a runtime graph: `verify.installed`, `verify.dispatch` (a warning with
     * `dispatch: sync`), `verify.transport`, then the adapter's sample check when it has one.
     *
     * @return list<CheckInterface>
     */
    public static function checksFor(VerifyConfig $verify, Services $services, string $queueWord, ?CheckInterface $sample = null): array
    {
        return [
            self::installedCheck($verify),
            self::dispatchCheck($verify, $services->config, $queueWord),
            self::transportCheck($verify, $services->config),
            ...$sample === null ? [] : [$sample],
        ];
    }
}
