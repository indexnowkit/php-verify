<?php

declare(strict_types=1);

namespace IndexNowKit\Verify;

/** `verify.origin_error`: what a URL the origin does not serve (401, 403, 5xx, any other 4xx, a transport failure) does. */
enum OriginErrorPolicy: string
{
    /** The URL is skipped with `Reason::OriginError` (retryable: a queue may try again). */
    case Skip = 'skip';
    /** The URL is submitted anyway, with a warning in the log. */
    case Send = 'send';
}
