<?php

declare(strict_types=1);

namespace IndexNowKit\Verify\Check;

use IndexNowKit\Check\CheckInterface;
use IndexNowKit\Check\CheckReport;

/**
 * The pre-flight relies on seeing 3xx answers itself: the redirect target is checked against the configured hosts,
 * `verify.max_redirects` counts the hops, `verify.timeout` bounds the GET. A client the application hands over as
 * `http.client` keeps its own settings — one that follows redirects internally makes all three silent. One warning.
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
            $report->warning(\sprintf('verify: the pre-flight uses http.client "%s" as configured by the application; make sure it does not follow redirects and has a timeout, or verify.max_redirects, verify.timeout and the host check on redirects do not apply', $this->client), self::CODE);
        }
    }
}
