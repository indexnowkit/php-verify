<?php

// The taint entry points of this package (audit 0.13 T20; bin/taint, .github/workflows/taint.yml): the public API called
// with request data, so that Psalm's taint analysis has a source to follow into the sinks (SQL, files, HTML, headers).
// A library has no taint source of its own — without this file Psalm reports nothing and proves nothing. Not a test:
// PHPUnit does not load it, phpstan analyses it at the level of the test suite, only psalm.xml lists it.

declare(strict_types=1);

namespace IndexNowKit\Taint;

use IndexNowKit\Http\Response;
use IndexNowKit\Testing\FakeTransport;
use IndexNowKit\Verify\PageSignals;
use IndexNowKit\Verify\RobotsCache;
use IndexNowKit\Verify\VerifyConfig;

/** A request value as a string: the taint of the superglobal, none of the mixed. */
function input(string $name): string
{
    $value = $_GET[$name] ?? $_POST[$name] ?? $_SERVER[$name] ?? null;

    return \is_string($value) ? $value : '';
}

/** @var array<string, mixed> $post */
$post = $_POST;
$verify = VerifyConfig::fromArray($post);
$body = file_get_contents('php://input');
$response = new Response(200, $body === false ? '' : $body, null, ['link' => input('link'), 'x-robots-tag' => input('x_robots_tag'), 'location' => input('location')]);
$signals = PageSignals::fromResponse(input('url'), $response);
echo $signals->canonical;
$robots = new RobotsCache(new FakeTransport(), keyPrefix: input('prefix'));
echo $robots->disallows(input('url'));
