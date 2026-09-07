<?php

declare(strict_types=1);

namespace IndexNowKit\Verify\Tests\Unit;

use IndexNowKit\Http\Response;
use IndexNowKit\Verify\PageSignals;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

final class PageSignalsTest extends TestCase
{
    private const URL = 'https://www.example.com/blog/post-1';

    /**
     * @return iterable<string, array{0: string, 1: bool, 2: string|null}>
     */
    public static function noindexHtml(): iterable
    {
        yield 'plain page' => ['<html><head><title>x</title></head><body>noindex in the body is text</body></html>', false, null];
        yield 'meta robots noindex' => ['<html><head><meta name="robots" content="noindex"></head><body></body></html>', true, PageSignals::SOURCE_META];
        yield 'noindex,nofollow' => ['<head><meta name="robots" content="noindex,nofollow"></head>', true, PageSignals::SOURCE_META];
        yield 'none' => ['<head><meta name="robots" content="NONE"></head>', true, PageSignals::SOURCE_META];
        yield 'uppercase and attribute order' => ['<HEAD><META CONTENT="NoIndex, follow" NAME="ROBOTS"></HEAD>', true, PageSignals::SOURCE_META];
        yield 'unquoted attributes' => ['<head><meta name=robots content=noindex></head>', true, PageSignals::SOURCE_META];
        yield 'single quotes' => ["<head><meta name='robots' content='nofollow, noindex'/></head>", true, PageSignals::SOURCE_META];
        yield 'index, follow' => ['<head><meta name="robots" content="index, follow, max-snippet:-1"></head>', false, null];
        yield 'noindex inside an HTML comment' => ['<head><!-- <meta name="robots" content="noindex"> --></head>', false, null];
        yield 'meta after </head> is ignored' => ['<head></head><body><meta name="robots" content="noindex"></body>', false, null];
        yield 'meta after <body> without </head>' => ['<head><title>x</title><body><meta name="robots" content="noindex">', false, null];
        yield 'head without closing tag' => ['<head><meta name="robots" content="noindex"><title>x</title>', true, PageSignals::SOURCE_META];
        yield 'bingbot meta' => ['<head><meta name="bingbot" content="noindex"></head>', true, PageSignals::SOURCE_META];
        yield 'yandex meta' => ['<head><meta name="yandex" content="noindex"></head>', true, PageSignals::SOURCE_META];
        yield 'googlebot meta is another engine' => ['<head><meta name="googlebot" content="noindex"></head>', false, null];
        yield 'description mentioning noindex' => ['<head><meta name="description" content="what noindex means"></head>', false, null];
    }

    #[DataProvider('noindexHtml')]
    public function testMetaRobots(string $html, bool $noindex, ?string $source): void
    {
        $signals = PageSignals::fromResponse(self::URL, new Response(200, $html, headers: ['Content-Type' => 'text/html; charset=utf-8']));

        self::assertSame($noindex, $signals->noindex);
        self::assertSame($source, $signals->noindexSource);
        self::assertSame(200, $signals->status);
        self::assertSame('text/html', $signals->contentType);
    }

    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function xRobotsTag(): iterable
    {
        yield 'noindex' => ['noindex', true];
        yield 'none' => ['none', true];
        yield 'noindex, nofollow' => ['noindex, nofollow', true];
        yield 'nofollow only' => ['nofollow', false];
        yield 'unavailable_after keeps its colon' => ['unavailable_after: 25 Jun 2030 15:00:00 PST, noindex', true];
        yield 'max-snippet keeps its colon' => ['max-snippet:20, noindex', true];
        yield 'googlebot prefix is ignored' => ['googlebot: noindex', false];
        yield 'googlebot prefix covers the following directives' => ['googlebot: noindex, nofollow', false];
        yield 'bingbot prefix' => ['bingbot: noindex', true];
        yield 'yandex prefix' => ['Yandex: none', true];
        yield 'googlebot then everybody' => ['googlebot: nofollow, otherbot: nothing', false];
        yield 'a googlebot directive does not swallow the global one after it' => ['googlebot: noindex, noindex', true];
        yield 'two headers joined by the header line' => ['googlebot: nofollow, none', true];
        yield 'mixed case' => ['NoIndex', true];
    }

    #[DataProvider('xRobotsTag')]
    public function testXRobotsTag(string $header, bool $noindex): void
    {
        $signals = PageSignals::fromResponse(self::URL, new Response(200, '<head></head>', headers: ['X-Robots-Tag' => $header]));

        self::assertSame($noindex, $signals->noindex);
        self::assertSame($noindex ? PageSignals::SOURCE_HEADER : null, $signals->noindexSource);
    }

    #[TestDox('the header wins over the meta as the source; a non-HTML body is not parsed')]
    public function testHeaderFirstAndNonHtml(): void
    {
        $both = PageSignals::fromResponse(self::URL, new Response(200, '<head><meta name="robots" content="noindex"></head>', headers: ['X-Robots-Tag' => 'noindex', 'Content-Type' => 'text/html']));
        self::assertSame(PageSignals::SOURCE_HEADER, $both->noindexSource);

        $pdf = PageSignals::fromResponse(self::URL, new Response(200, '<head><meta name="robots" content="noindex"><link rel="canonical" href="/x"></head>', headers: ['Content-Type' => 'application/pdf']));
        self::assertFalse($pdf->noindex);
        self::assertNull($pdf->canonical);
        self::assertSame('application/pdf', $pdf->contentType);

        $xhtml = PageSignals::fromResponse(self::URL, new Response(200, '<head><meta name="robots" content="noindex"></head>', headers: ['Content-Type' => 'application/xhtml+xml']));
        self::assertTrue($xhtml->noindex);

        $unknown = PageSignals::fromResponse(self::URL, new Response(200, '<head><meta name="robots" content="noindex"></head>'));
        self::assertTrue($unknown->noindex, 'without a Content-Type (a custom transport) the body is parsed');
        self::assertNull($unknown->contentType);
    }

    /**
     * @return iterable<string, array{0: string, 1: array<string, string>, 2: string|null}>
     */
    public static function canonicals(): iterable
    {
        yield 'absolute link' => ['<head><link rel="canonical" href="https://www.example.com/blog/post-1"></head>', [], 'https://www.example.com/blog/post-1'];
        yield 'relative href' => ['<head><link rel="canonical" href="post-1-final"></head>', [], 'https://www.example.com/blog/post-1-final'];
        yield 'absolute path href' => ['<head><link href="/posts/1" rel="canonical" /></head>', [], 'https://www.example.com/posts/1'];
        yield 'scheme-relative href' => ['<head><link rel="canonical" href="//cdn.example.com/p"></head>', [], 'https://cdn.example.com/p'];
        yield 'dot segments' => ['<head><link rel="canonical" href="../other/./x"></head>', [], 'https://www.example.com/other/x'];
        yield 'entity in href' => ['<head><link rel="canonical" href="/p?a=1&amp;b=2"></head>', [], 'https://www.example.com/p?a=1&b=2'];
        yield 'first canonical wins' => ['<head><link rel="canonical" href="/first"><link rel="canonical" href="/second"></head>', [], 'https://www.example.com/first'];
        yield 'rel with several values' => ['<head><link rel="alternate canonical" href="/multi"></head>', [], 'https://www.example.com/multi'];
        yield 'stylesheet is not canonical' => ['<head><link rel="stylesheet" href="/style.css"></head>', [], null];
        yield 'empty href' => ['<head><link rel="canonical" href=""></head>', [], null];
        yield 'Link header' => ['<head></head>', ['Link' => '<https://www.example.com/hdr>; rel="canonical"'], 'https://www.example.com/hdr'];
        yield 'Link header, several links and rel values' => ['<head></head>', ['Link' => '</style.css>; rel="preload"; as="style", </hdr2>; rel="alternate canonical"'], 'https://www.example.com/hdr2'];
        yield 'Link header unquoted rel' => ['<head></head>', ['Link' => '</hdr3>; rel=canonical'], 'https://www.example.com/hdr3'];
        yield 'Link header without canonical' => ['<head></head>', ['Link' => '</next>; rel="next"'], null];
        yield 'header before the link tag' => ['<head><link rel="canonical" href="/tag"></head>', ['Link' => '</header>; rel="canonical"'], 'https://www.example.com/header'];
        yield 'canonical in a comment' => ['<head><!-- <link rel="canonical" href="/old"> --></head>', [], null];
        yield 'base href, relative canonical' => ['<head><base href="/en/"><link rel="canonical" href="page"></head>', [], 'https://www.example.com/en/page'];
        yield 'absolute base href' => ['<head><base href="https://cdn.example.com/x/"><link rel="canonical" href="p"></head>', [], 'https://cdn.example.com/x/p'];
        yield 'base href after the link tag still applies' => ['<head><link rel="canonical" href="page"><base href="/en/"></head>', [], 'https://www.example.com/en/page'];
        yield 'base href leaves an absolute canonical alone' => ['<head><base href="https://cdn.example.com/"><link rel="canonical" href="https://www.example.com/abs"></head>', [], 'https://www.example.com/abs'];
        yield 'base without href' => ['<head><base target="_blank"><link rel="canonical" href="rel"></head>', [], 'https://www.example.com/blog/rel'];
        yield 'base href does not apply to the Link header' => ['<head><base href="/en/"></head>', ['Link' => '<hdr>; rel="canonical"'], 'https://www.example.com/blog/hdr'];
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('canonicals')]
    public function testCanonical(string $html, array $headers, ?string $canonical): void
    {
        self::assertSame($canonical, PageSignals::fromResponse(self::URL, new Response(200, $html, headers: $headers))->canonical);
    }

    #[TestDox('a body longer than MAX_BYTES is cut: a meta after the limit is not seen')]
    public function testMaxBytes(): void
    {
        $padding = str_repeat('<meta name="viewport" content="width=device-width">' . "\n", intdiv(PageSignals::MAX_BYTES, 50) + 10);
        $late = PageSignals::fromResponse(self::URL, new Response(200, '<head>' . $padding . '<meta name="robots" content="noindex"></head>'));
        self::assertFalse($late->noindex);

        $early = PageSignals::fromResponse(self::URL, new Response(200, '<head><meta name="robots" content="noindex">' . $padding . '</head>'));
        self::assertTrue($early->noindex);
    }

    public function testLocation(): void
    {
        self::assertSame('https://www.example.com/blog/new', PageSignals::fromResponse(self::URL, new Response(301, '', headers: ['Location' => '/blog/new']))->location);
        self::assertSame('https://other.example.com/x', PageSignals::fromResponse(self::URL, new Response(302, '', headers: ['Location' => 'https://other.example.com/x']))->location);
        self::assertNull(PageSignals::fromResponse(self::URL, new Response(302))->location);
        self::assertSame(410, PageSignals::fromResponse(self::URL, new Response(410))->status);
    }
}
