<?php

declare(strict_types=1);

namespace IndexNowKit\Verify\Check;

use Closure;
use IndexNowKit\Check\CheckInterface;
use IndexNowKit\Check\CheckReport;
use IndexNowKit\Exception\InvalidUrlException;
use IndexNowKit\Http\TransportInterface;
use IndexNowKit\Key\KeyProviderInterface;
use IndexNowKit\Url\UrlNormalizerInterface;
use IndexNowKit\Verify\PageSignals;
use IndexNowKit\Verify\RobotsCache;
use IndexNowKit\Verify\VerifyConfig;
use Throwable;

/**
 * `check --sample=<url>` / `--sample-class=<FQCN>[:<id>]`: one GET per sample and one line with what an engine would
 * see — status, noindex, canonical, robots.txt. Every finding is a **warning, never an error**: production may be
 * unreachable from the CI that runs `check --strict`, and a page that happens to be noindex must not fail a deploy.
 * The adapter registers it among the checker's extra checks with the options of the command; without a sample the
 * line says how to give one.
 */
final class SampleCheck implements CheckInterface
{
    /** The code of every line this check prints ({@see \IndexNowKit\Check\CheckItem::$code}). */
    public const CODE = 'verify.sample';
    /** Objects fetched per `--sample-class=<FQCN>` without an id. */
    public const PER_CLASS = 3;

    private readonly RobotsCache $robots;

    /**
     * @param list<string>                                     $urls         `--sample` values
     * @param list<string>                                     $classes      `--sample-class` values, `<FQCN>` or `<FQCN>:<id>`
     * @param KeyProviderInterface                             $keys         a sample on a host outside `hosts` / `base_url` is a warning
     * @param (Closure(string, string|null): list<string>)|null $classSampler the adapter's "class[, id] → URLs" over its loader
     *                                                                       and URL resolver (up to {@see PER_CLASS} objects
     *                                                                       without an id); null = `--sample-class` unsupported
     * @param RobotsCache|null                                 $robots       null = robots.txt once per host, not shared
     */
    public function __construct(
        private readonly array $urls,
        private readonly array $classes,
        private readonly TransportInterface $transport,
        private readonly VerifyConfig $config,
        private readonly UrlNormalizerInterface $normalizer,
        private readonly KeyProviderInterface $keys,
        private readonly ?Closure $classSampler = null,
        ?RobotsCache $robots = null,
    ) {
        $this->robots = $robots ?? new RobotsCache($transport, null, '', $config->robotsCacheTtl);
    }

    public function check(CheckReport $report): void
    {
        if ($this->urls === [] && $this->classes === []) {
            $report->ok('verify sample: no sample given (check --sample=<url>, --sample-class=<class>)', self::CODE);

            return;
        }
        foreach ($this->urls as $url) {
            $this->sample($report, $url);
        }
        foreach ($this->classes as $spec) {
            foreach ($this->classUrls($report, $spec) as $url) {
                $this->sample($report, $url);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function classUrls(CheckReport $report, string $spec): array
    {
        [$class, $id] = array_pad(explode(':', $spec, 2), 2, null);
        $class = trim((string) $class);
        $id = $id === null || trim($id) === '' ? null : trim($id);
        if ($this->classSampler === null) {
            $report->warning(\sprintf('verify sample %s: --sample-class is not supported by this adapter; give URLs with --sample', $spec), self::CODE);

            return [];
        }
        try {
            $urls = ($this->classSampler)($class, $id);
        } catch (Throwable $e) {
            $report->warning(\sprintf('verify sample %s: %s', $spec, $e->getMessage()), self::CODE);

            return [];
        }
        if ($urls === []) {
            $report->warning(\sprintf('verify sample %s: no URL (no such %s, or its #[IndexNow] rules produced none for the updated event)', $spec, $id === null ? 'objects' : 'object'), self::CODE);
        }

        return $urls;
    }

    private function sample(CheckReport $report, string $url): void
    {
        try {
            $normalized = $this->normalizer->normalize($url);
        } catch (InvalidUrlException $e) {
            $report->warning(\sprintf('verify sample %s: %s', $url, $e->getMessage()), self::CODE);

            return;
        }
        $host = $this->normalizer->hostOf($normalized);
        if (!$this->isConfiguredHost($host)) {
            $report->warning(\sprintf('verify sample %s: host %s is not one of the configured hosts (hosts / base_url); not fetched', $normalized, $host), self::CODE, $host);

            return;
        }
        $rule = $this->robots->disallows($normalized);
        try {
            $response = $this->transport->get($normalized);
        } catch (Throwable $e) {
            $report->warning(\sprintf('verify sample %s: cannot fetch (%s); the pre-flight would skip it as origin_error', $normalized, $e->getMessage()), self::CODE, $host);

            return;
        }
        $signals = PageSignals::fromResponse($normalized, $response);
        $status = $signals->status;
        $facts = [\sprintf('HTTP %d', $status)];
        $problems = [];
        if ($status >= 300 && $status < 400) {
            $problems[] = 'redirect';
            $facts[] = 'redirects to ' . ($signals->location ?? '?');
        } elseif ($status === 404 || $status === 410) {
            $facts[] = 'gone (would be submitted as a deletion)';
        } elseif ($status < 200 || $status >= 300) {
            $problems[] = 'origin_error';
        }
        if ($status >= 200 && $status < 300) {
            if ($signals->noindex) {
                $problems[] = 'noindex';
                $facts[] = \sprintf('noindex (%s)', (string) $signals->noindexSource);
            } else {
                $facts[] = 'index';
            }
            $canonical = $this->canonical($normalized, $signals->canonical);
            if ($canonical === null) {
                $facts[] = 'canonical: self';
            } else {
                $problems[] = 'non_canonical';
                $facts[] = 'canonical: ' . $canonical;
            }
        }
        if ($rule !== null) {
            $problems[] = 'robots_disallowed';
            $facts[] = \sprintf('robots: disallowed (%s)', $rule);
        } else {
            $facts[] = 'robots: allowed';
        }
        $line = \sprintf('verify sample %s: %s', $normalized, implode(', ', $facts));
        if ($problems === []) {
            $report->ok($line, self::CODE, $host);
        } else {
            $report->warning($line . \sprintf(' — the pre-flight would skip it (%s)', implode(', ', $problems)), self::CODE, $host);
        }
    }

    /** A host the key provider enumerates (`hosts`, `base_url`), or when it enumerates none, one it has a key for. */
    private function isConfiguredHost(string $host): bool
    {
        $managed = $this->keys->managedHosts();

        return $managed !== [] ? \in_array(strtolower($host), array_map('strtolower', $managed), true) : $this->keys->keyFor($host) !== null;
    }

    /** The canonical when it is another URL than the page (after normalization), else null. */
    private function canonical(string $page, ?string $canonical): ?string
    {
        if ($canonical === null) {
            return null;
        }
        try {
            $normalized = $this->normalizer->normalize($canonical);
        } catch (InvalidUrlException) {
            return null;
        }

        return $normalized === $page ? null : $normalized;
    }
}
