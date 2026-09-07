<?php

declare(strict_types=1);

namespace IndexNowKit\Verify\Check;

use IndexNowKit\Check\CheckInterface;
use IndexNowKit\Check\CheckReport;

/**
 * The pre-flight relies on seeing 3xx answers itself: the redirect target is checked against the configured hosts,
 * `verify.max_redirects` counts the hops, `verify.timeout` bounds the GET. PSR-18 has no way to tell a client the
 * application handed over as `http.client` not to follow redirects, so the pre-flight does not use it: it builds its
 * own client with `max_redirects: 0` ({@see VerifyConfig::transportConfig()}), while `http.client` keeps carrying the
 * POST submissions. One line saying which client does what, so a split transport is never a surprise in a bug report.
 */
final class TransportCheck implements CheckInterface
{
    public const CODE = 'verify.transport';

    /**
     * @param bool        $enabled `verify.enabled`
     * @param string|null $client  `http.client` of the core configuration
     */
    public function __construct(private readonly bool $enabled, private readonly ?string $client) {}

    public function check(CheckReport $report): void
    {
        if ($this->enabled && $this->client !== null) {
            $report->ok(\sprintf('verify: the pre-flight uses its own PSR-18 client with verify.timeout and no redirects, not http.client "%s"; that client sends the submissions', $this->client), self::CODE);
        }
    }
}
