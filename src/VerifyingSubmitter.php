<?php

declare(strict_types=1);

namespace IndexNowKit\Verify;

use Closure;
use IndexNowKit\Clock\SystemClock;
use IndexNowKit\Exception\InvalidUrlException;
use IndexNowKit\Http\TransportInterface;
use IndexNowKit\Key\KeyProviderInterface;
use IndexNowKit\Reason;
use IndexNowKit\Result;
use IndexNowKit\ResultStatus;
use IndexNowKit\Submission\SubmissionStoreInterface;
use IndexNowKit\SubmitterInterface;
use IndexNowKit\Url\UrlNormalizerInterface;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * The pre-flight decorator: one GET of every URL before the decorated submitter sends it, so a page that says
 * `noindex`, that robots.txt disallows, that names another canonical URL, that redirects or that the origin does not
 * serve never reaches the engines — each becomes a skipped {@see Result} with the reason. 404 and 410 pass: they are
 * the deletion signal IndexNow accepts. The adapters decorate the submitter of the container, so queue and Messenger
 * workers verify too; `enabled: false` is a transparent delegate (zero GETs).
 *
 * Notifications: every Result — the inner ones and the skipped ones of this decorator — reaches the listeners
 * registered here exactly once; the skipped ones are also published to the PSR-14 dispatcher and recorded in the
 * submission store, as the inner submitter does for its own.
 */
final class VerifyingSubmitter implements SubmitterInterface
{
    private const LOG = 'indexnow verify: ';

    /** @var list<callable(Result): void> */
    private array $listeners = [];
    private readonly RobotsCache $robots;
    private readonly ClockInterface $clock;
    /** @var Closure(int): void */
    private readonly Closure $sleep;

    /**
     * @param SubmitterInterface            $inner        the submitter of the adapter (`Submitter`, or an already decorated one)
     * @param TransportInterface            $transport    the pre-flight transport: `verify.timeout`, `verify.user_agent`, no redirects
     *                                                    (`TransportFactory::lazy($config->transportConfig($core), $locator,
     *                                                    ['User-Agent' => $config->userAgent()])`)
     * @param KeyProviderInterface          $keys         which hosts have a key: a redirect or a canonical to a host without one is a skip
     * @param EventDispatcherInterface|null $events       the PSR-14 dispatcher of the inner submitter, for the skipped results
     * @param SubmissionStoreInterface|null $store        the submission store of the inner submitter, for the skipped results
     * @param RobotsCache|null              $robots       null = robots.txt fetched once per host and process, not shared
     * @param bool                          $inWebRequest true while serving a web request (`dispatch: sync`): `verify.delay` is ignored
     * @param (Closure(int): void)|null     $sleep        how `verify.delay` waits (tests); default `sleep()`
     */
    public function __construct(
        private readonly SubmitterInterface $inner,
        private readonly TransportInterface $transport,
        private readonly VerifyConfig $config,
        private readonly KeyProviderInterface $keys,
        private readonly UrlNormalizerInterface $normalizer,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?EventDispatcherInterface $events = null,
        private readonly ?SubmissionStoreInterface $store = null,
        ?RobotsCache $robots = null,
        ?ClockInterface $clock = null,
        private readonly bool $inWebRequest = false,
        ?Closure $sleep = null,
    ) {
        $this->robots = $robots ?? new RobotsCache($transport, null, '', $config->robotsCacheTtl, $logger);
        $this->clock = $clock ?? new SystemClock();
        $this->sleep = $sleep ?? static function (int $seconds): void {
            sleep($seconds);
        };
    }

    public function submit(iterable $urls): array
    {
        $urls = \is_array($urls) ? array_values($urls) : iterator_to_array($urls, false);
        if (!$this->config->enabled) {
            return $this->finish($this->inner->submit($urls), []);
        }
        $planned = $this->inner->prepare($urls);
        if (\count($planned) > $this->config->maxBatch) {
            $this->logger->warning(self::LOG . 'batch of {count} URLs exceeds verify.max_batch ({max}), sent unverified; use sitemap --no-verify or raise the limit', ['count' => \count($planned), 'max' => $this->config->maxBatch]);

            return $this->finish($this->inner->submit($urls), []);
        }
        $toCheck = array_values(array_filter($planned, fn(string $url): bool => $this->hasKey($url)));
        if ($toCheck !== [] && $this->config->delay > 0 && !$this->inWebRequest) {
            ($this->sleep)($this->config->delay);
        }

        $skipped = [];   // normalized URL => Result
        $extra = [];     // URLs submitted in addition (a canonical, a permanent redirect target)
        $deadline = $this->config->timeBudget > 0 ? $this->clock->now()->getTimestamp() + $this->config->timeBudget : null;
        foreach ($toCheck as $i => $url) {
            if ($deadline !== null && $i > 0 && $this->clock->now()->getTimestamp() >= $deadline) {
                // A queue job has a visibility timeout: past the budget the rest goes unverified rather than to a second worker too.
                $this->logger->warning(self::LOG . 'verify.time_budget of {budget} s spent after {checked} of {count} URLs; the remaining {left} sent unverified', ['budget' => $this->config->timeBudget, 'checked' => $i, 'count' => \count($toCheck), 'left' => \count($toCheck) - $i]);
                break;
            }
            $verdict = $this->verify($url);
            if ($verdict->skip !== null) {
                $skipped[$url] = $verdict->skip;
            }
            foreach ($verdict->extra as $extraUrl) {
                $extra[$extraUrl] = true;
            }
        }

        $forward = [];
        foreach ($urls as $url) {
            try {
                if (isset($skipped[$this->normalizer->normalize($url)])) {
                    continue;
                }
            } catch (InvalidUrlException) {
                // the inner submitter reports it
            }
            $forward[] = $url;
        }
        foreach (array_keys($extra) as $extraUrl) {
            if (!isset($skipped[$extraUrl])) {
                $forward[] = $extraUrl;
            }
        }

        return $this->finish($forward === [] ? [] : $this->inner->submit($forward), array_values($skipped));
    }

    public function prepare(iterable $urls): array
    {
        return $this->inner->prepare($urls);
    }

    /**
     * Kept here, not forwarded: the inner submitter never sees the skipped results, so the listeners must be called
     * by the decorator — with every result, each exactly once.
     */
    public function addListener(callable $listener): void
    {
        $this->listeners[] = $listener;
    }

    /**
     * @param list<Result> $inner
     * @param list<Result> $skipped
     *
     * @return list<Result>
     */
    private function finish(array $inner, array $skipped): array
    {
        $results = [...$inner, ...$skipped];
        foreach ($results as $result) {
            foreach ($this->listeners as $listener) {
                try {
                    $listener($result);
                } catch (Throwable $e) {
                    $this->logger->error('indexnow: result listener failed: {error}', ['error' => $e->getMessage(), 'exception' => $e]);
                }
            }
        }
        foreach ($skipped as $result) {
            try {
                $this->events?->dispatch($result);
            } catch (Throwable $e) {
                $this->logger->error('indexnow: result event listener failed: {error}', ['error' => $e->getMessage(), 'exception' => $e]);
            }
        }
        if ($this->store !== null && $skipped !== []) {
            try {
                $at = $this->clock->now();
                foreach ($skipped as $result) {
                    $this->store->record($result, $at);
                }
            } catch (Throwable $e) {
                $this->logger->error('indexnow: submission store failed, {count} result(s) not recorded: {error}', ['count' => \count($skipped), 'error' => $e->getMessage(), 'exception' => $e]);
            }
        }

        return $results;
    }

    /** The pre-flight of one normalized URL: a skip, or the go-ahead with the URLs to submit in addition. */
    private function verify(string $url): Verdict
    {
        $final = $this->fetch($url);
        if ($final instanceof Verdict) {
            return $final;
        }
        [$signals, $current, $permanent] = $final;
        $status = $signals->status;
        if ($status === 404 || $status === 410) {
            $this->logger->info(self::LOG . '{url} gone (HTTP {status}), submitted as a deletion', ['url' => $current, 'status' => $status]);

            return $this->allowed($url, $current, $permanent);
        }
        if ($status < 200 || $status >= 300) {
            $hint = $status === 401 || $status === 403 ? \sprintf('HTTP %d: the origin refuses the pre-flight GET; on a protected environment set verify.enabled: false', $status) : \sprintf('HTTP %d', $status);

            return $this->originError($url, $hint, $current);
        }
        if ($signals->noindex) {
            return $this->skip($url, Reason::Noindex, \sprintf('noindex (%s)', (string) $signals->noindexSource), 'noindex ({source})', ['source' => $signals->noindexSource, 'page' => $current]);
        }
        if ($signals->canonical !== null) {
            $verdict = $this->canonical($url, $current, $signals->canonical);
            if ($verdict !== null) {
                return $verdict;
            }
        }

        return $this->allowed($url, $current, $permanent);
    }

    /**
     * robots.txt, then the GET, following redirects under `redirect: follow`: the signals of the final answer, the
     * final URL and whether every hop was permanent — or the skip that ended the chain.
     *
     * @return Verdict|array{0: PageSignals, 1: string, 2: bool}
     */
    private function fetch(string $url): Verdict|array
    {
        $current = $url;
        $visited = [$url => true];
        $permanent = true;
        for ($hop = 0; ; ++$hop) {
            $rule = $this->robots->disallows($current);
            if ($rule !== null) {
                return $this->skip($url, Reason::RobotsDisallowed, \sprintf('robots.txt disallows (%s)', $rule), 'robots.txt disallows ({rule})', ['rule' => $rule]);
            }
            try {
                $response = $this->transport->get($current);
            } catch (Throwable $e) {
                return $this->originError($url, $e->getMessage(), $current);
            }
            $signals = PageSignals::fromResponse($current, $response);
            $status = $signals->status;
            if ($status < 300 || $status >= 400) {
                return [$signals, $current, $permanent];
            }
            $location = $signals->location;
            $context = ['location' => $location ?? '?', 'status' => $status];
            if ($this->config->redirect === RedirectPolicy::Skip || $location === null) {
                return $this->skip($url, Reason::Redirected, \sprintf('redirected to %s (%d)', $location ?? '?', $status), 'redirected to {location} ({status})', $context);
            }
            if (!$this->isSubmittable($location)) {
                return $this->skip($url, Reason::Redirected, \sprintf('redirected to %s (%d): redirect leaves the configured hosts', $location, $status), 'redirected to {location} ({status}): redirect leaves the configured hosts', $context);
            }
            try {
                $next = $this->normalizer->normalize($location);
            } catch (InvalidUrlException $e) {
                return $this->skip($url, Reason::Redirected, \sprintf('redirected to %s (%d): %s', $location, $status, $e->getMessage()), 'redirected to {location} ({status}): {error}', $context + ['error' => $e->getMessage()]);
            }
            if (isset($visited[$next])) {
                return $this->skip($url, Reason::Redirected, \sprintf('redirected to %s (%d): redirect loop', $location, $status), 'redirected to {location} ({status}): redirect loop', $context);
            }
            if ($hop >= $this->config->maxRedirects) {
                return $this->skip($url, Reason::Redirected, \sprintf('redirected to %s (%d): more than %d redirects', $location, $status, $this->config->maxRedirects), 'redirected to {location} ({status}): more than {max} redirects', $context + ['max' => $this->config->maxRedirects]);
            }
            $visited[$next] = true;
            $permanent = $permanent && ($status === 301 || $status === 308);
            $current = $next;
        }
    }

    /** null when the canonical is the page itself (equal after normalization) or unusable. */
    private function canonical(string $url, string $current, string $canonical): ?Verdict
    {
        try {
            $normalized = $this->normalizer->normalize($canonical);
        } catch (InvalidUrlException $e) {
            $this->logger->debug(self::LOG . '{url} declares an invalid canonical {canonical}, ignored: {error}', ['url' => $current, 'canonical' => $canonical, 'error' => $e->getMessage()]);

            return null;
        }
        if ($normalized === $current) {
            return null;
        }
        if ($this->config->nonCanonical === NonCanonicalPolicy::Replace) {
            if (!$this->isSubmittable($normalized)) {
                return $this->skip($url, Reason::NonCanonical, \sprintf('canonical is %s: canonical points to a foreign host', $normalized), 'canonical is {canonical}: canonical points to a foreign host', ['canonical' => $normalized]);
            }
            $this->logger->info(self::LOG . 'replaced {url} with canonical {canonical}', ['url' => $url, 'canonical' => $normalized]);

            return new Verdict(Result::skipped($this->normalizer->hostOf($url), [$url], Reason::NonCanonical, \sprintf('canonical is %s: submitted instead', $normalized)), [$normalized]);
        }

        return $this->skip($url, Reason::NonCanonical, \sprintf('canonical is %s', $normalized), 'canonical is {canonical}', ['canonical' => $normalized]);
    }

    private function originError(string $url, string $error, string $current): Verdict
    {
        if ($this->config->originError === OriginErrorPolicy::Send) {
            $this->logger->warning(self::LOG . '{url} origin error ({error}), sent anyway (verify.origin_error: send)', ['url' => $current, 'error' => $error]);

            return new Verdict(null, []);
        }
        $this->logger->warning(self::LOG . 'skipped {url}: origin error ({error})', ['url' => $url, 'error' => $error, 'page' => $current]);

        return new Verdict(new Result(Result::NO_ENGINE, $this->normalizer->hostOf($url), [$url], ResultStatus::Skipped, null, \sprintf('origin error (%s)', $error), true, null, '', Reason::OriginError), []);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function skip(string $url, Reason $reason, string $error, string $message, array $context): Verdict
    {
        $this->logger->info(self::LOG . 'skipped {url}: ' . $message, ['url' => $url] + $context);

        return new Verdict(Result::skipped($this->normalizer->hostOf($url), [$url], $reason, $error), []);
    }

    /** After a permanent redirect the target is submitted too (the page moved); after a temporary one only the origin. */
    private function allowed(string $url, string $final, bool $permanent): Verdict
    {
        return new Verdict(null, $final !== $url && $permanent ? [$final] : []);
    }

    private function hasKey(string $normalizedUrl): bool
    {
        return $this->keys->keyFor($this->normalizer->hostOf($normalizedUrl)) !== null;
    }

    /**
     * Where a redirect or a canonical may lead: http(s), and a host of the configuration — one the key provider
     * enumerates (`hosts`, `base_url`), or when it enumerates none, one it has a key for. A URL of another site is
     * never submitted on the strength of a header the site under check sent.
     */
    private function isSubmittable(string $url): bool
    {
        $parts = parse_url($url);
        if (!\is_array($parts) || !isset($parts['scheme'], $parts['host']) || !\in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return false;
        }
        try {
            $host = $this->normalizer->hostOf($this->normalizer->normalize($url));
        } catch (InvalidUrlException) {
            return false;
        }
        $managed = $this->keys->managedHosts();

        return $managed !== [] ? \in_array(strtolower($host), array_map('strtolower', $managed), true) : $this->keys->keyFor($host) !== null;
    }
}
