<?php

declare(strict_types=1);

namespace IndexNowKit\Verify\Tests\Unit;

use IndexNowKit\Http\Response;
use IndexNowKit\Testing\ArrayLogger;
use IndexNowKit\Testing\FakeTransport;
use IndexNowKit\Verify\RobotsCache;
use IndexNowKit\Verify\Tests\Support\ArrayCache;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RobotsCacheTest extends TestCase
{
    private const ROBOTS = "User-agent: *\nDisallow: /private/\nAllow: /private/open\n\nUser-agent: Googlebot\nDisallow: /google-only/\n";

    #[TestDox('one GET per host and process; Disallow rules of * and of the engines\' bots apply, Googlebot\'s do not')]
    public function testPerProcess(): void
    {
        $transport = (new FakeTransport())->onGet('https://www.example.com/robots.txt', new Response(200, self::ROBOTS));
        $robots = new RobotsCache($transport);

        self::assertSame('Disallow: /private/', $robots->disallows('https://www.example.com/private/page'));
        self::assertNull($robots->disallows('https://www.example.com/private/open-day'));
        self::assertNull($robots->disallows('https://www.example.com/google-only/x'));
        self::assertNull($robots->disallows('https://www.example.com/'));
        self::assertSame(['https://www.example.com/robots.txt'], $transport->gets, 'fetched once');
        self::assertNull($robots->disallows('not a url'));
    }

    #[TestDox('404: no robots.txt, everything allowed, no warning; 500 or a transport failure: allowed with one warning per host')]
    public function testUnavailable(): void
    {
        $logger = new ArrayLogger();
        $transport = (new FakeTransport())
            ->onGet('https://down.example.com/robots.txt', new Response(500))
            ->onGet('https://gone.example.com/robots.txt', FakeTransport::failing('timeout'));
        $robots = new RobotsCache($transport, logger: $logger);

        self::assertNull($robots->disallows('https://www.example.com/a'));
        self::assertNull($robots->disallows('https://www.example.com/b'));
        self::assertSame([], $logger->messages('warning'), '404 is the normal "no robots.txt"');
        self::assertNull($robots->disallows('https://down.example.com/a'));
        self::assertNull($robots->disallows('https://down.example.com/b'));
        self::assertNull($robots->disallows('https://gone.example.com/a'));
        self::assertSame(3, \count(array_filter($transport->gets, static fn(string $u): bool => str_ends_with($u, '/robots.txt'))), 'one GET per host, a failed host is not retried in the process');
        self::assertSame(['indexnow verify: robots.txt of down.example.com unavailable (HTTP 500), URLs are treated as allowed', 'indexnow verify: robots.txt of gone.example.com unavailable (timeout), URLs are treated as allowed'], $logger->messages('warning'));
    }

    #[TestDox('the PSR-16 cache shares the body between processes under <prefix>robots.<host> with the TTL; ttl 0 does not touch the cache')]
    public function testShared(): void
    {
        $cache = new ArrayCache();
        $first = (new FakeTransport())->onGet('https://www.example.com/robots.txt', new Response(200, self::ROBOTS));
        $a = new RobotsCache($first, $cache, 'app_', 600);
        self::assertSame('Disallow: /private/', $a->disallows('https://www.example.com/private/x'));
        self::assertSame(self::ROBOTS, $cache->values['app_robots.www.example.com']);
        self::assertSame(600, $cache->ttls['app_robots.www.example.com']);
        self::assertSame('app_robots.www.example.com', $a->key('www.example.com'));
        self::assertSame('app_robots.www.example.com', $a->key('https://www.example.com'), 'https on 443 is the plain host key');
        self::assertSame('app_robots.http_www.example.com_8080', $a->key('http://www.example.com:8080'), 'another origin, another file');
        self::assertMatchesRegularExpression('/^[^{}()\/\\\\@:]+$/', $a->key('http://www.example.com:8080'), 'no PSR-6 reserved character');
        $staging = (new FakeTransport())->onGet('http://www.example.com:8080/robots.txt', new Response(200, "User-agent: *\nDisallow: /"));
        $s = new RobotsCache($staging, $cache, 'app_', 600);
        self::assertSame('Disallow: /', $s->disallows('http://www.example.com:8080/private/x'), 'staging on another port has its own robots.txt');
        self::assertSame('Disallow: /private/', $a->disallows('https://www.example.com/private/z'), 'and production keeps its own');

        $second = new FakeTransport();
        $b = new RobotsCache($second, $cache, 'app_', 600);
        self::assertSame('Disallow: /private/', $b->disallows('https://www.example.com/private/y'));
        self::assertSame([], $second->gets, 'served from the shared cache');

        $none = (new FakeTransport())->onGet('https://www.example.com/robots.txt', new Response(404));
        $c = new RobotsCache($none, new ArrayCache(), 'app_', 600);
        self::assertNull($c->disallows('https://www.example.com/private/y'));

        $third = (new FakeTransport())->onGet('https://www.example.com/robots.txt', new Response(200, self::ROBOTS));
        $off = new RobotsCache($third, $cache = new ArrayCache(), 'app_', 0);
        self::assertSame('Disallow: /private/', $off->disallows('https://www.example.com/private/z'));
        self::assertSame([], $cache->values);
    }

    #[TestDox('a failing cache is one warning; robots.txt is fetched per process')]
    public function testBrokenCache(): void
    {
        $broken = new class extends ArrayCache {
            public function get($key, $default = null): mixed
            {
                throw new RuntimeException('redis down');
            }
        };
        $logger = new ArrayLogger();
        $transport = (new FakeTransport())->onGet('https://www.example.com/robots.txt', new Response(200, self::ROBOTS));
        $robots = new RobotsCache($transport, $broken, 'app_', 600, $logger);

        self::assertSame('Disallow: /private/', $robots->disallows('https://www.example.com/private/x'));
        self::assertSame('Disallow: /private/', $robots->disallows('https://www.example.com/private/y'));
        self::assertCount(1, $logger->messages('warning'));
        self::assertStringContainsString('robots cache unavailable', $logger->messages('warning')[0]);
    }
}
