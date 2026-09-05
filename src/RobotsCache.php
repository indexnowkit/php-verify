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

    /** @var array<string, string> host => robots.txt body of this process */
    private array $bodies = [];
    /** @var array<string, true> hosts whose robots.txt failed in this process (warned once) */
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
        $robots = $this->body($host, $scheme . '://' . $host . (isset($parts['port']) ? ':' . $parts['port'] : ''));
        if ($robots === self::NONE) {
            return null;
        }
        $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');

        return Checker::robotsDisallows($robots, $path === '' ? '/' : $path);
    }

    /** `<prefix>robots.<host>`: no PSR-6 reserved character (`{}()/\@:`), host names have none. */
    public function key(string $host): string
    {
        return $this->keyPrefix . 'robots.' . $host;
    }

    private function body(string $host, string $origin): string
    {
        if (isset($this->bodies[$host])) {
            return $this->bodies[$host];
        }
        if (isset($this->failed[$host])) {
            return self::NONE;
        }
        $cached = $this->cached($host);
        if ($cached !== null) {
            return $this->bodies[$host] = $cached;
        }
        $body = $this->fetch($host, $origin);
        if ($body === null) {
            $this->failed[$host] = true;

            return self::NONE;
        }
        $this->store($host, $body);

        return $this->bodies[$host] = $body;
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

    private function cached(string $host): ?string
    {
        if ($this->cache === null || $this->ttl <= 0) {
            return null;
        }
        try {
            $value = $this->cache->get($this->key($host));
        } catch (Throwable $e) {
            $this->warnCache($e);

            return null;
        }

        return \is_string($value) ? $value : null;
    }

    private function store(string $host, string $body): void
    {
        if ($this->cache === null || $this->ttl <= 0) {
            return;
        }
        try {
            $this->cache->set($this->key($host), $body, $this->ttl);
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
