<?php

declare(strict_types=1);

namespace IndexNowKit\Verify;

/** `verify.non_canonical`: what a page whose canonical URL is another URL does. */
enum NonCanonicalPolicy: string
{
    /** The URL is skipped with `Reason::NonCanonical`. */
    case Skip = 'skip';
    /** The canonical URL is submitted instead, when its host has a key; a foreign canonical is a skip. */
    case Replace = 'replace';
}
