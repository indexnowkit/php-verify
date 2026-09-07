<?php

declare(strict_types=1);

namespace IndexNowKit\Verify;

use BackedEnum;
use Composer\InstalledVersions;
use IndexNowKit\Config;
use IndexNowKit\Exception\ConfigurationException;
use Psr\Log\LoggerInterface;

/**
 * The `verify` block of an adapter's configuration, validated once. Off by default: installing the package changes
 * nothing until `verify.enabled: true`. Adapters build it with {@see fromArray()} from the raw block, hand it to
 * {@see VerifyingSubmitter} and {@see Check\SampleCheck}, and add {@see OPTIONS} to the keys they accept.
 */
final readonly class VerifyConfig
{
    /** Every key of the block, dotted-path form, for `Config::unknownOptions()`. */
    public const OPTIONS = [
        'verify.enabled', 'verify.redirect', 'verify.non_canonical', 'verify.origin_error', 'verify.delay',
        'verify.timeout', 'verify.max_redirects', 'verify.max_batch', 'verify.time_budget', 'verify.robots_cache_ttl', 'verify.user_agent',
    ];

    public const DEFAULT_TIMEOUT = 5.0;
    public const DEFAULT_MAX_REDIRECTS = 3;
    /** A batch above it is sent unverified with one warning: a sitemap run is the site's own list of its URLs. */
    public const DEFAULT_MAX_BATCH = 100;
    /** Seconds the pre-flight of one batch may take in total; what is left when it runs out is sent unverified with a warning. */
    public const DEFAULT_TIME_BUDGET = 60;
    /** Bytes of a page the pre-flight GET reads at most: the signals live in the head, `PageSignals::MAX_BYTES` of it. */
    public const BODY_LIMIT = 1_048_576;
    public const DEFAULT_ROBOTS_CACHE_TTL = 3600;
    /** Upper bound of `verify.delay`: a queue worker sleeping longer than this is a queue nobody watches. */
    public const MAX_DELAY = 30;
    /** Fallback of {@see defaultUserAgent()} when Composer's runtime metadata is unavailable. */
    public const VERSION = '0.1.0';

    /**
     * @param bool               $enabled         false = the adapter registers no decorator, nothing is fetched
     * @param RedirectPolicy     $redirect        what a 3xx answer does: skip the URL, or follow to the target
     * @param NonCanonicalPolicy $nonCanonical    what a canonical pointing elsewhere does: skip, or submit the canonical
     * @param OriginErrorPolicy  $originError     what a 401/403/5xx/transport failure does: skip, or send anyway
     * @param int                $delay           seconds to wait before the first GET of a batch outside a web request
     *                                            (a queue worker right after the commit; the page may not be published
     *                                            yet behind a cache); ignored with `dispatch: sync`
     * @param float              $timeout         seconds one GET may take
     * @param int                $maxRedirects    hops followed with `redirect: follow`; more is a skip
     * @param int                $maxBatch        largest batch verified; a larger one is sent unverified with a warning
     * @param int                $timeBudget      seconds the pre-flight of one batch may take in total (a queue job has a
     *                                            visibility timeout); the URLs left when it runs out are sent unverified
     *                                            with a warning. 0 = no budget
     * @param int                $robotsCacheTtl  seconds a fetched robots.txt is kept in the PSR-16 cache (0 = per process only)
     * @param string|null        $userAgent       `User-Agent` of the pre-flight GETs; null = {@see defaultUserAgent()}
     *
     * @throws ConfigurationException
     */
    public function __construct(
        public bool $enabled = false,
        public RedirectPolicy $redirect = RedirectPolicy::Skip,
        public NonCanonicalPolicy $nonCanonical = NonCanonicalPolicy::Skip,
        public OriginErrorPolicy $originError = OriginErrorPolicy::Skip,
        public int $delay = 0,
        public float $timeout = self::DEFAULT_TIMEOUT,
        public int $maxRedirects = self::DEFAULT_MAX_REDIRECTS,
        public int $maxBatch = self::DEFAULT_MAX_BATCH,
        public int $timeBudget = self::DEFAULT_TIME_BUDGET,
        public int $robotsCacheTtl = self::DEFAULT_ROBOTS_CACHE_TTL,
        public ?string $userAgent = null,
    ) {
        if ($delay < 0 || $delay > self::MAX_DELAY) {
            throw new ConfigurationException(\sprintf('"verify.delay" must be between 0 and %d seconds, got %d.', self::MAX_DELAY, $delay));
        }
        if ($timeout <= 0) {
            throw new ConfigurationException(\sprintf('"verify.timeout" must be > 0 seconds, got %s.', $timeout));
        }
        if ($maxRedirects < 0) {
            throw new ConfigurationException(\sprintf('"verify.max_redirects" must be >= 0, got %d.', $maxRedirects));
        }
        if ($maxBatch < 1) {
            throw new ConfigurationException(\sprintf('"verify.max_batch" must be >= 1, got %d.', $maxBatch));
        }
        if ($timeBudget < 0) {
            throw new ConfigurationException(\sprintf('"verify.time_budget" must be >= 0 seconds, got %d.', $timeBudget));
        }
        if ($robotsCacheTtl < 0) {
            throw new ConfigurationException(\sprintf('"verify.robots_cache_ttl" must be >= 0, got %d.', $robotsCacheTtl));
        }
        if ($userAgent !== null && ($userAgent === '' || preg_match('/[\r\n]/', $userAgent) === 1)) {
            throw new ConfigurationException('"verify.user_agent" must be a non-empty single line or null.');
        }
    }

    /**
     * From the raw `verify` block of a framework configuration. Numbers and booleans are coerced the way
     * `Config::fromArray()` does it (`"3"`, `"true"`, `"0"`); anything else is a ConfigurationException naming the key.
     *
     * @param array<string, mixed> $block
     *
     * @throws ConfigurationException
     */
    public static function fromArray(array $block): self
    {
        return new self(
            enabled: self::bool($block['enabled'] ?? null, false, 'verify.enabled'),
            redirect: self::policy(RedirectPolicy::class, $block['redirect'] ?? null, RedirectPolicy::Skip, 'verify.redirect'),
            nonCanonical: self::policy(NonCanonicalPolicy::class, $block['non_canonical'] ?? null, NonCanonicalPolicy::Skip, 'verify.non_canonical'),
            originError: self::policy(OriginErrorPolicy::class, $block['origin_error'] ?? null, OriginErrorPolicy::Skip, 'verify.origin_error'),
            delay: self::int($block['delay'] ?? null, 0, 'verify.delay'),
            timeout: self::float($block['timeout'] ?? null, self::DEFAULT_TIMEOUT, 'verify.timeout'),
            maxRedirects: self::int($block['max_redirects'] ?? null, self::DEFAULT_MAX_REDIRECTS, 'verify.max_redirects'),
            maxBatch: self::int($block['max_batch'] ?? null, self::DEFAULT_MAX_BATCH, 'verify.max_batch'),
            timeBudget: self::int($block['time_budget'] ?? null, self::DEFAULT_TIME_BUDGET, 'verify.time_budget'),
            robotsCacheTtl: self::int($block['robots_cache_ttl'] ?? null, self::DEFAULT_ROBOTS_CACHE_TTL, 'verify.robots_cache_ttl'),
            userAgent: self::str($block['user_agent'] ?? null),
        );
    }

    /** The block of an adapter whose verify support is off (the default, and the fallback of {@see loadOrDisabled()}). */
    public static function disabled(): self
    {
        return new self(enabled: false);
    }

    /**
     * The runtime path of an adapter: {@see fromArray()}, and when the block is invalid one `critical` line naming the
     * error and the check command, then {@see disabled()} — submissions go on unverified, nothing throws from the
     * container.
     *
     * @param array<string, mixed> $block        the raw `verify` block
     * @param string               $checkCommand the adapter's check command, `php artisan indexnow:check`
     */
    public static function loadOrDisabled(array $block, LoggerInterface $logger, string $checkCommand): self
    {
        try {
            return self::fromArray($block);
        } catch (ConfigurationException $e) {
            $logger->critical('indexnow verify: invalid verify configuration, pre-flight checks are off until it is fixed: {error} (run "{check}")', ['error' => $e->getMessage(), 'check' => $checkCommand, 'exception' => $e]);

            return self::disabled();
        }
    }

    /**
     * The effective block in the form of {@see fromArray()} (`indexnow:config` prints it as the `verify` section).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'redirect' => $this->redirect->value,
            'non_canonical' => $this->nonCanonical->value,
            'origin_error' => $this->originError->value,
            'delay' => $this->delay,
            'timeout' => $this->timeout,
            'max_redirects' => $this->maxRedirects,
            'max_batch' => $this->maxBatch,
            'time_budget' => $this->timeBudget,
            'robots_cache_ttl' => $this->robotsCacheTtl,
            'user_agent' => $this->userAgent,
        ];
    }

    /** `verify.user_agent`, or `indexnowkit-verify/<version> (+https://github.com/indexnowkit/php)`. */
    public function userAgent(): string
    {
        return $this->userAgent ?? self::defaultUserAgent();
    }

    public static function defaultUserAgent(): string
    {
        $version = self::VERSION;
        if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled('indexnowkit/verify')) {
            $installed = ltrim((string) InstalledVersions::getPrettyVersion('indexnowkit/verify'), 'v');
            if (preg_match('/^\d+\.\d+\.\d+(?:[-.][0-9A-Za-z.-]+)?$/', $installed) === 1) {
                $version = $installed;
            }
        }

        return 'indexnowkit-verify/' . $version . ' (+https://github.com/indexnowkit/php)';
    }

    /**
     * The core configuration the pre-flight transport is built from: every option as it is, `http.timeout` =
     * `verify.timeout`, and **`http.client` dropped**. The pre-flight has to see the 3xx answers itself — the host
     * check on a redirect, `verify.max_redirects` and the loop detection all read them — and PSR-18 has no way to
     * tell a client the application handed over not to follow redirects, so the pre-flight always uses the client the
     * core discovers with `max_redirects: 0`. `http.client` still carries the POST submissions. The `User-Agent` goes
     * as an extra header (`TransportFactory::lazy($config->transportConfig($core), null, ['User-Agent' => $config->userAgent()])`).
     */
    public function transportConfig(Config $core): Config
    {
        return $core->with(httpTimeout: $this->timeout, httpClient: null);
    }

    /**
     * @template T of \BackedEnum
     *
     * @param class-string<T> $enum
     * @param T               $default
     *
     * @return T
     *
     * @throws ConfigurationException
     */
    private static function policy(string $enum, mixed $value, BackedEnum $default, string $option): BackedEnum
    {
        if ($value === null || $value === '') {
            return $default;
        }
        $policy = \is_string($value) ? $enum::tryFrom(strtolower($value)) : null;
        if ($policy === null) {
            throw new ConfigurationException(\sprintf('"%s" must be one of %s, got "%s".', $option, implode(', ', array_map(static fn(BackedEnum $c): string => (string) $c->value, $enum::cases())), \is_scalar($value) ? (string) $value : get_debug_type($value)));
        }

        return $policy;
    }

    private static function str(mixed $value): ?string
    {
        return \is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @throws ConfigurationException
     */
    private static function int(mixed $value, int $default, string $option): int
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if (!is_numeric($value) || (string) (int) $value !== ltrim((string) $value, '+')) {
            throw new ConfigurationException(\sprintf('"%s" must be an integer, got "%s".', $option, \is_scalar($value) ? (string) $value : get_debug_type($value)));
        }

        return (int) $value;
    }

    /**
     * @throws ConfigurationException
     */
    private static function float(mixed $value, float $default, string $option): float
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if (!is_numeric($value)) {
            throw new ConfigurationException(\sprintf('"%s" must be a number, got "%s".', $option, \is_scalar($value) ? (string) $value : get_debug_type($value)));
        }

        return (float) $value;
    }

    /**
     * @throws ConfigurationException
     */
    private static function bool(mixed $value, bool $default, string $option): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if (\is_bool($value)) {
            return $value;
        }
        $parsed = \is_scalar($value) ? filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) : null;
        if ($parsed === null) {
            throw new ConfigurationException(\sprintf('"%s" must be a boolean, got "%s".', $option, \is_scalar($value) ? (string) $value : get_debug_type($value)));
        }

        return $parsed;
    }
}
