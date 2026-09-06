<?php

declare(strict_types=1);

namespace IndexNowKit\Verify\Check;

use IndexNowKit\Check\CheckInterface;
use IndexNowKit\Check\CheckReport;

/**
 * `verify.enabled` with `dispatch: sync` fetches the application's own pages inside the web request: one warning naming the
 * adapter's queue mode. The same line in every adapter (spec 17 §6.1).
 */
final class DispatchCheck implements CheckInterface
{
    public const CODE = 'verify.dispatch';

    /**
     * @param bool   $warn      `verify.enabled && dispatch === 'sync'`
     * @param string $queueMode the adapter's queue mode: `queue`, `messenger`
     */
    public function __construct(private readonly bool $warn, private readonly string $queueMode = 'queue') {}

    public function check(CheckReport $report): void
    {
        if ($this->warn) {
            $report->warning(\sprintf('verify: verify.enabled with dispatch: sync fetches your own pages inside the web request; use dispatch: %s', $this->queueMode), self::CODE);
        }
    }
}
