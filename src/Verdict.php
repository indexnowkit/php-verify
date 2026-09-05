<?php

declare(strict_types=1);

namespace IndexNowKit\Verify;

use IndexNowKit\Result;

/**
 * The outcome of the pre-flight of one URL: a skipped Result (the URL is not submitted), or the go-ahead with the
 * URLs submitted in addition (the canonical under `non_canonical: replace`, the target of a permanent redirect).
 *
 * @internal the value {@see VerifyingSubmitter} passes around
 */
final readonly class Verdict
{
    /**
     * @param list<string> $extra
     */
    public function __construct(public ?Result $skip, public array $extra) {}
}
