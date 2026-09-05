<?php

declare(strict_types=1);

namespace IndexNowKit\Verify\Tests\Unit;

use Closure;
use IndexNowKit\Check\CheckLevel;
use IndexNowKit\Check\CheckReport;
use IndexNowKit\Http\Response;
use IndexNowKit\Key\StaticKeyProvider;
use IndexNowKit\Testing\FakeTransport;
use IndexNowKit\Url\UrlNormalizerFactory;
use IndexNowKit\Verify\Check\SampleCheck;
use IndexNowKit\Verify\Tests\Support\Factory;
use IndexNowKit\Verify\VerifyConfig;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SampleCheckTest extends TestCase
{
    /**
     * @param list<string> $urls
     * @param list<string> $classes
     */
    private static function check(FakeTransport $transport, array $urls, array $classes = [], ?Closure $sampler = null): CheckReport
    {
        $config = Factory::config();
        $report = new CheckReport();
        (new SampleCheck($urls, $classes, $transport, VerifyConfig::fromArray([]), UrlNormalizerFactory::fromConfig($config), StaticKeyProvider::fromConfig($config), $sampler))->check($report);

        return $report;
    }

    /**
     * @return list<string> "level|code|host|message"
     */
    private static function lines(CheckReport $report): array
    {
        return array_map(static fn($i): string => $i->level->value . '|' . $i->code . '|' . $i->host . '|' . $i->message, $report->items());
    }

    #[TestDox('no sample: one ok line saying how to give one')]
    public function testNoSample(): void
    {
        self::assertSame(['ok|verify.sample||verify sample: no sample given (check --sample=<url>, --sample-class=<class>)'], self::lines(self::check(new FakeTransport(), [])));
    }

    #[TestDox('one line per URL: status, index/noindex, canonical, robots; a clean page is ok, every finding is a warning, never an error')]
    public function testSamples(): void
    {
        $transport = (new FakeTransport())
            ->onGet('https://www.example.com/robots.txt', new Response(200, "User-agent: *\nDisallow: /private/"))
            ->onGet('https://www.example.com/clean', new Response(200, '<head><link rel="canonical" href="/clean?utm_source=x"></head>'))
            ->onGet('https://www.example.com/noindex', new Response(200, '<head><meta name="robots" content="noindex"></head>'))
            ->onGet('https://www.example.com/other', new Response(200, '<head><link rel="canonical" href="/canonical"></head>'))
            ->onGet('https://www.example.com/private/x', new Response(200, '<head></head>'))
            ->onGet('https://www.example.com/moved', new Response(301, '', headers: ['Location' => '/new']))
            ->onGet('https://www.example.com/gone', new Response(410))
            ->onGet('https://www.example.com/down', new Response(503))
            ->onGet('https://www.example.com/dead', FakeTransport::failing('timeout'));
        $report = self::check($transport, ['/clean', 'https://www.example.com/noindex', '/other', '/private/x', '/moved', '/gone', '/down', '/dead', 'https://foreign.example.org/x', 'ftp://www.example.com/x']);

        self::assertSame([
            'ok|verify.sample|www.example.com|verify sample https://www.example.com/clean: HTTP 200, index, canonical: self, robots: allowed',
            'warning|verify.sample|www.example.com|verify sample https://www.example.com/noindex: HTTP 200, noindex (meta robots), canonical: self, robots: allowed — the pre-flight would skip it (noindex)',
            'warning|verify.sample|www.example.com|verify sample https://www.example.com/other: HTTP 200, index, canonical: https://www.example.com/canonical, robots: allowed — the pre-flight would skip it (non_canonical)',
            'warning|verify.sample|www.example.com|verify sample https://www.example.com/private/x: HTTP 200, index, canonical: self, robots: disallowed (Disallow: /private/) — the pre-flight would skip it (robots_disallowed)',
            'warning|verify.sample|www.example.com|verify sample https://www.example.com/moved: HTTP 301, redirects to https://www.example.com/new, robots: allowed — the pre-flight would skip it (redirect)',
            'ok|verify.sample|www.example.com|verify sample https://www.example.com/gone: HTTP 410, gone (would be submitted as a deletion), robots: allowed',
            'warning|verify.sample|www.example.com|verify sample https://www.example.com/down: HTTP 503, robots: allowed — the pre-flight would skip it (origin_error)',
            'warning|verify.sample|www.example.com|verify sample https://www.example.com/dead: cannot fetch (timeout); the pre-flight would skip it as origin_error',
            'warning|verify.sample|foreign.example.org|verify sample https://foreign.example.org/x: host foreign.example.org is not one of the configured hosts (hosts / base_url); not fetched',
        ], \array_slice(self::lines($report), 0, 9));
        self::assertStringStartsWith('warning|verify.sample||verify sample ftp://www.example.com/x: ', self::lines($report)[9]);
        self::assertSame(CheckLevel::Warning, $report->status(), 'never above warning');
        self::assertNotContains('https://foreign.example.org/x', $transport->gets, 'a foreign host is not fetched');
        self::assertCount(1, array_filter($transport->gets, static fn(string $u): bool => str_ends_with($u, '/robots.txt')), 'robots.txt once');
    }

    #[TestDox('--sample-class: the adapter\'s sampler gives the URLs; a throwing or empty sampler is a warning; no sampler at all is a warning')]
    public function testSampleClass(): void
    {
        $transport = (new FakeTransport())->onGet('https://www.example.com/posts/1', new Response(200))->onGet('https://www.example.com/posts/2', new Response(200));
        $calls = [];
        $sampler = static function (string $class, ?string $id) use (&$calls): array {
            $calls[] = [$class, $id];

            return match ($class) {
                'App\Post' => $id === null ? ['/posts/1', '/posts/2'] : ['/posts/' . $id],
                'App\Empty' => [],
                default => throw new RuntimeException(\sprintf('class %s is not annotated with #[IndexNow]', $class)),
            };
        };

        $report = self::check($transport, [], ['App\Post', 'App\Post:2', 'App\Empty', 'App\Nope:7'], $sampler);

        self::assertSame([['App\Post', null], ['App\Post', '2'], ['App\Empty', null], ['App\Nope', '7']], $calls);
        self::assertSame([
            'ok|verify.sample|www.example.com|verify sample https://www.example.com/posts/1: HTTP 200, index, canonical: self, robots: allowed',
            'ok|verify.sample|www.example.com|verify sample https://www.example.com/posts/2: HTTP 200, index, canonical: self, robots: allowed',
            'ok|verify.sample|www.example.com|verify sample https://www.example.com/posts/2: HTTP 200, index, canonical: self, robots: allowed',
            'warning|verify.sample||verify sample App\Empty: no URL (no such objects, or its #[IndexNow] rules produced none for the updated event)',
            'warning|verify.sample||verify sample App\Nope:7: class App\Nope is not annotated with #[IndexNow]',
        ], self::lines($report));

        self::assertSame(['warning|verify.sample||verify sample App\Post: --sample-class is not supported by this adapter; give URLs with --sample'], self::lines(self::check($transport, [], ['App\Post'])));
    }
}
