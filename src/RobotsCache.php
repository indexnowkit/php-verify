<?php

declare(strict_types=1);

namespace IndexNowKit\Verify;

use IndexNowKit\Check\Checker;
use IndexNowKit\Http\TransportInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;
use Throwable;

/**
 * robots.txt per host: one GET per host and process, and the body in the PSR-16 cache the adapter shares
 * (`<debounce.key_prefix>robots.<host>`, `verify.robots_cache_ttl`) so a fleet of workers fetches it once an hour.
 * A robots.txt that cannot be fetched (not 200 and not 404, or a transport failure) blocks nothing: one `warning`
 * per host and process, every path is allowed — an unreachable robots.txt is no reason to keep quiet.
 */
final class RobotsCache
{
    /** The body stored for a host without robots.txt (404): every path allowed, nothing fetched again. */
    private const NONE = '';

    /** @var array<string, string> origin => robots.txt body of this process */
    private array $bodies = [];
    /** @var array<string, true> origins whose robots.txt failed in this process (warned once) */
    private array $failed = [];
    private bool $cacheWarned = false;

    /**
     * @param CacheInterface|null $cache     the shared PSR-16 cache; null = per process
     * @param string              $keyPrefix `debounce.key_prefix` of the core configuration
     * @param int                 $ttl       `verify.robots_cache_ttl`; 0 = the cache is not used
     */
    public function __construct(
        private readonly TransportInterface $transport,
        private readonly ?CacheInterface $cache = null,
        private readonly string $keyPrefix = '',
        private readonly int $ttl = VerifyConfig::DEFAULT_ROBOTS_CACHE_TTL,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * The `Disallow` rule of the host's robots.txt that covers the path of $url for every bot or for an IndexNow
     * engine's bot ({@see Checker::robotsDisallows()}), null when the path is allowed or robots.txt is unavailable.
     */
    public function disallows(string $url): ?string
    {
        $parts = parse_url($url);
        if (!\is_array($parts) || !isset($parts['host'])) {
            return null;
        }
        $host = strtolower($parts['host']);
        $scheme = strtolower($parts['scheme'] ?? 'https');
        $port = $parts['port'] ?? null;
        // Staging on http://host:8080 and production on https://host are different origins with different robots.txt files.
        $robots = $this->body($host, $scheme . '://' . $host . ($port !== null && $port !== ($scheme === 'https' ? 443 : 80) ? ':' . $port : ''));
        if ($robots === self::NONE) {
            return null;
        }
        $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');

        return Checker::robotsDisallows($robots, $path === '' ? '/' : $path);
    }

    /**
     * `<prefix>robots.<host>` for the https origin on its default port, `<prefix>robots.<scheme>_<host>_<port>` otherwise:
     * no PSR-6 reserved character (`{}()/\@:`). $origin is a host or a `scheme://host[:port]`. An IPv6 literal keeps
     * its brackets and colons from `parse_url()`, so it goes through the same stripping the core's `ForbiddenCounter`
     * does (`[::1]` becomes `__1`).
     */
    public function key(string $origin): string
    {
        if (!str_contains($origin, '://')) {
            return $this->keyPrefix . 'robots.' . self::hostKey($origin);
        }
        $parts = parse_url($origin);
        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $host = self::hostKey(strtolower((string) ($parts['host'] ?? '')));
        $port = $parts['port'] ?? null;
        if ($scheme === 'https' && $port === null) {
            return $this->keyPrefix . 'robots.' . $host;
        }

        return $this->keyPrefix . 'robots.' . $scheme . '_' . $host . ($port === null ? '' : '_' . $port);
    }

    /** A host as a PSR-16 key segment: an IPv6 literal loses its brackets, its colons become underscores. */
    private static function hostKey(string $host): string
    {
        return strtr($host, ['[' => '', ']' => '', ':' => '_']);
    }

    private function body(string $host, string $origin): string
    {
        if (isset($this->bodies[$origin])) {
            return $this->bodies[$origin];
        }
        if (isset($this->failed[$origin])) {
            return self::NONE;
        }
        $cached = $this->cached($origin);
        if ($cached !== null) {
            return $this->bodies[$origin] = $cached;
        }
        $body = $this->fetch($host, $origin);
        if ($body === null) {
            $this->failed[$origin] = true;

            return self::NONE;
        }
        $this->store($origin, $body);

        return $this->bodies[$origin] = $body;
    }

    /** null when robots.txt is unavailable (warned once per host and process). */
    private function fetch(string $host, string $origin): ?string
    {
        $url = $origin . '/robots.txt';
        try {
            $response = $this->transport->get($url);
        } catch (Throwable $e) {
            $this->logger->warning('indexnow verify: robots.txt of {host} unavailable ({error}), URLs are treated as allowed', ['host' => $host, 'error' => $e->getMessage()]);

            return null;
        }
        if ($response->status === 200) {
            return $response->body === '' ? ' ' : $response->body;
        }
        if ($response->status === 404) {
            return self::NONE;
        }
        $this->logger->warning('indexnow verify: robots.txt of {host} unavailable (HTTP {status}), URLs are treated as allowed', ['host' => $host, 'status' => $response->status]);

        return null;
    }

    private function cached(string $origin): ?string
    {
        if ($this->cache === null || $this->ttl <= 0) {
            return null;
        }
        try {
            $value = $this->cache->get($this->key($origin));
        } catch (Throwable $e) {
            $this->warnCache($e);

            return null;
        }

        return \is_string($value) ? $value : null;
    }

    private function store(string $origin, string $body): void
    {
        if ($this->cache === null || $this->ttl <= 0) {
            return;
        }
        try {
            $this->cache->set($this->key($origin), $body, $this->ttl);
        } catch (Throwable $e) {
            $this->warnCache($e);
        }
    }

    private function warnCache(Throwable $e): void
    {
        if ($this->cacheWarned) {
            return;
        }
        $this->cacheWarned = true;
        $this->logger->warning('indexnow verify: robots cache unavailable, fetching robots.txt per process: {error}', ['error' => $e->getMessage(), 'exception' => $e]);
    }
}
