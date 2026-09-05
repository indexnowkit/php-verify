<?php

declare(strict_types=1);

namespace IndexNowKit\Verify;

/** `verify.redirect`: what a URL that answers 3xx does. */
enum RedirectPolicy: string
{
    /** The URL is skipped with `Reason::Redirected`; submit the target yourself. */
    case Skip = 'skip';
    /**
     * The redirect chain is followed (`verify.max_redirects` hops, http(s), hosts with a key only) and the target
     * verified; after a permanent redirect (301/308) both URLs are submitted, after a temporary one (302/303/307)
     * only the original.
     */
    case Follow = 'follow';
}
