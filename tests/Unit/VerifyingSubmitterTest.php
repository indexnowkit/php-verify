<?php

declare(strict_types=1);

namespace IndexNowKit\Verify\Tests\Unit;

use IndexNowKit\Http\Response;
use IndexNowKit\Reason;
use IndexNowKit\Result;
use IndexNowKit\ResultStatus;
use IndexNowKit\Testing\ArrayLogger;
use IndexNowKit\Testing\FakeTransport;
use IndexNowKit\Testing\FrozenClock;
use IndexNowKit\Verify\Tests\Support\ArrayCache;
use IndexNowKit\Verify\Tests\Support\Factory;
use IndexNowKit\Verify\Tests\Support\RecordingEvents;
use IndexNowKit\Verify\Tests\Support\RecordingStore;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

final class VerifyingSubmitterTest extends TestCase
{
    private const A = 'https://www.example.com/a';
    private const B = 'https://www.example.com/b';
    private const ROBOTS = 'https://www.example.com/robots.txt';

    /**
     * @return list<string>
     */
    private static function posted(FakeTransport $transport): array
    {
        $urls = [];
        foreach ($transport->posts as $post) {
            /** @var list<string> $list */
            $list = $post['body']['urlList'];
            $urls = [...$urls, ...$list];
        }

        return $urls;
    }

    /**
     * @param list<Result> $results
     *
     * @return list<string> "status:reason:url" per result and URL
     */
    private static function outcome(array $results): array
    {
        $out = [];
        foreach ($results as $result) {
            foreach ($result->urls as $url) {
                $out[] = $result->status->value . ':' . ($result->reason?->value ?? '-') . ':' . $url;
            }
        }
        sort($out);

        return $out;
    }

    #[TestDox('an indexable page passes: GET then POST; skipped results carry the reason and never reach the engine')]
    public function testNoindexIsSkipped(): void
    {
        $transport = Factory::transport()
            ->onGet(self::A, new Response(200, '<head><title>a</title></head>'))
            ->onGet(self::B, new Response(200, '<head><meta name="robots" content="noindex"></head>'));
        $logger = new ArrayLogger();
        [$submitter] = Factory::submitters($transport, logger: $logger);

        $results = $submitter->submit([self::A, self::B, self::A]);

        self::assertSame([self::A], self::posted($transport));
        self::assertSame(['ok:-:' . self::A, 'skipped:noindex:' . self::B], self::outcome($results));
        self::assertSame([self::ROBOTS, self::A, self::B], $transport->gets, 'robots.txt once, then the pages');
        self::assertCount(1, $transport->posts);
        self::assertContains('indexnow verify: skipped https://www.example.com/b: noindex (meta robots)', $logger->messages('info'));
        $skipped = $results[1];
        self::assertSame(Result::NO_ENGINE, $skipped->engine);
        self::assertSame('www.example.com', $skipped->host);
        self::assertSame('noindex (meta robots)', $skipped->error);
        self::assertFalse($skipped->retryable);
    }

    public function testXRobotsTagAndRobotsTxt(): void
    {
        $transport = Factory::transport()
            ->onGet(self::ROBOTS, new Response(200, "User-agent: *\nDisallow: /b"))
            ->onGet(self::A, new Response(200, '', headers: ['X-Robots-Tag' => 'noindex']));
        $logger = new ArrayLogger();
        [$submitter] = Factory::submitters($transport, logger: $logger);

        $results = $submitter->submit([self::A, self::B]);

        self::assertSame([], self::posted($transport));
        self::assertSame(['skipped:noindex:' . self::A, 'skipped:robots_disallowed:' . self::B], self::outcome($results));
        self::assertNotContains(self::B, $transport->gets, 'a disallowed URL is not fetched');
        self::assertContains('indexnow verify: skipped https://www.example.com/a: noindex (X-Robots-Tag)', $logger->messages('info'));
        self::assertContains('indexnow verify: skipped https://www.example.com/b: robots.txt disallows (Disallow: /b)', $logger->messages('info'));
    }

    #[TestDox('404 and 410 are submitted as deletions')]
    public function testGonePagesPass(): void
    {
        $transport = Factory::transport()->onGet(self::A, new Response(410))->onGet(self::B, new Response(404, 'not found'));
        $logger = new ArrayLogger();
        [$submitter] = Factory::submitters($transport, logger: $logger);

        $results = $submitter->submit([self::A, self::B]);

        self::assertSame([self::A, self::B], self::posted($transport));
        self::assertSame(['ok:-:' . self::A, 'ok:-:' . self::B], self::outcome($results));
        self::assertContains('indexnow verify: https://www.example.com/a gone (HTTP 410), submitted as a deletion', $logger->messages('info'));
    }

    #[TestDox('401/403/5xx/other 4xx and a transport failure are OriginError (retryable); origin_error: send submits with a warning')]
    public function testOriginError(): void
    {
        $pages = static fn(): FakeTransport => Factory::transport()
            ->onGet(self::A, new Response(401))
            ->onGet(self::B, new Response(503))
            ->onGet('https://www.example.com/c', FakeTransport::failing('connection refused'))
            ->onGet('https://www.example.com/d', new Response(418));
        $urls = [self::A, self::B, 'https://www.example.com/c', 'https://www.example.com/d'];

        $transport = $pages();
        $logger = new ArrayLogger();
        [$submitter] = Factory::submitters($transport, logger: $logger);
        $results = $submitter->submit($urls);
        self::assertSame([], self::posted($transport));
        self::assertCount(4, $results);
        foreach ($results as $result) {
            self::assertSame(ResultStatus::Skipped, $result->status);
            self::assertSame(Reason::OriginError, $result->reason);
            self::assertTrue($result->retryable, 'a queue may try again');
        }
        self::assertSame('origin error (HTTP 401: the origin refuses the pre-flight GET; on a protected environment set verify.enabled: false)', $results[0]->error);
        self::assertSame('origin error (HTTP 503)', $results[1]->error);
        self::assertSame('origin error (connection refused)', $results[2]->error);
        self::assertSame('origin error (HTTP 418)', $results[3]->error);
        self::assertCount(4, $logger->messages('warning'));
        self::assertStringStartsWith('indexnow verify: skipped https://www.example.com/a: origin error (HTTP 401', $logger->messages('warning')[0]);

        $transport = $pages();
        $logger = new ArrayLogger();
        [$submitter] = Factory::submitters($transport, ['origin_error' => 'send'], logger: $logger);
        $results = $submitter->submit($urls);
        self::assertSame($urls, self::posted($transport));
        self::assertCount(1, $results, 'one batch, one Result');
        self::assertSame(ResultStatus::Ok, $results[0]->status);
        self::assertStringContainsString('origin error (HTTP 401', $logger->messages('warning')[0]);
        self::assertStringContainsString('sent anyway (verify.origin_error: send)', $logger->messages('warning')[0]);
    }

    #[TestDox('redirect: skip (the default) skips every 3xx with the target in the text')]
    public function testRedirectSkip(): void
    {
        $transport = Factory::transport()->onGet(self::A, new Response(301, '', headers: ['Location' => '/a-new']));
        [$submitter] = Factory::submitters($transport);

        $results = $submitter->submit([self::A]);

        self::assertSame([], self::posted($transport));
        self::assertSame(['skipped:redirected:' . self::A], self::outcome($results));
        self::assertSame('redirected to https://www.example.com/a-new (301)', $results[0]->error);
    }

    #[TestDox('redirect: follow — 301/308 submit both URLs, 302/303/307 only the origin; the target is verified too')]
    public function testRedirectFollow(): void
    {
        $transport = Factory::transport()
            ->onGet(self::A, new Response(301, '', headers: ['Location' => '/a-new']))
            ->onGet('https://www.example.com/a-new', new Response(200, '<head></head>'))
            ->onGet(self::B, new Response(302, '', headers: ['Location' => 'https://www.example.com/b-temp']))
            ->onGet('https://www.example.com/b-temp', new Response(200, '<head></head>'))
            ->onGet('https://www.example.com/c', new Response(308, '', headers: ['Location' => '/c-noindex']))
            ->onGet('https://www.example.com/c-noindex', new Response(200, '<head><meta name="robots" content="noindex"></head>'));
        [$submitter] = Factory::submitters($transport, ['redirect' => 'follow']);

        $results = $submitter->submit([self::A, self::B, 'https://www.example.com/c']);

        self::assertSame([self::A, self::B, 'https://www.example.com/a-new'], self::posted($transport), 'the origin URLs, then the permanent target');
        self::assertSame(['ok:-:' . self::A, 'ok:-:https://www.example.com/a-new', 'ok:-:' . self::B, 'skipped:noindex:https://www.example.com/c'], self::outcome($results));
        self::assertSame('noindex (meta robots)', $results[1]->error, 'a noindex target skips the origin');
    }

    #[TestDox('redirect: follow — foreign host, loop, too many hops and a non-http target are skips')]
    public function testRedirectFollowLimits(): void
    {
        $transport = Factory::transport()
            ->onGet(self::A, new Response(301, '', headers: ['Location' => 'https://other.example.org/a']))
            ->onGet(self::B, new Response(302, '', headers: ['Location' => '/b2']))
            ->onGet('https://www.example.com/b2', new Response(302, '', headers: ['Location' => '/b']))
            ->onGet('https://www.example.com/c', new Response(301, '', headers: ['Location' => '/c1']))
            ->onGet('https://www.example.com/c1', new Response(301, '', headers: ['Location' => '/c2']))
            ->onGet('https://www.example.com/c2', new Response(301, '', headers: ['Location' => '/c3']))
            ->onGet('https://www.example.com/d', new Response(301, '', headers: ['Location' => 'ftp://www.example.com/d']))
            ->onGet('https://www.example.com/e', new Response(301));
        [$submitter] = Factory::submitters($transport, ['redirect' => 'follow', 'max_redirects' => 1]);

        $results = $submitter->submit([self::A, self::B, 'https://www.example.com/c', 'https://www.example.com/d', 'https://www.example.com/e']);

        self::assertSame([], self::posted($transport));
        self::assertCount(5, $results);
        self::assertSame('redirected to https://other.example.org/a (301): redirect leaves the configured hosts', $results[0]->error);
        self::assertSame('redirected to https://www.example.com/b (302): redirect loop', $results[1]->error);
        self::assertSame('redirected to https://www.example.com/c2 (301): more than 1 redirects', $results[2]->error);
        self::assertSame('redirected to ftp://www.example.com/d (301): redirect leaves the configured hosts', $results[3]->error);
        self::assertSame('redirected to ? (301)', $results[4]->error);
        self::assertNotContains('https://other.example.org/a', $transport->gets, 'a foreign host is never fetched');
        self::assertNotContains('https://www.example.com/c2', $transport->gets);
    }

    #[TestDox('canonical: equal after normalization is the page itself; another URL skips, or replaces with non_canonical: replace; a foreign canonical skips')]
    public function testCanonical(): void
    {
        $pages = static fn(): FakeTransport => Factory::transport()
            ->onGet(self::A, new Response(200, '<head><link rel="canonical" href="https://www.example.com/a?utm_source=x"></head>'))
            ->onGet(self::B, new Response(200, '<head><link rel="canonical" href="/b-canonical"></head>'))
            ->onGet('https://www.example.com/c', new Response(200, '<head><link rel="canonical" href="https://other.example.org/c"></head>'))
            ->onGet('https://www.example.com/b-canonical', new Response(200, '<head></head>'));
        $urls = [self::A, self::B, 'https://www.example.com/c'];

        $transport = $pages();
        [$submitter] = Factory::submitters($transport);
        $results = $submitter->submit($urls);
        self::assertSame([self::A], self::posted($transport), 'tracking parameters are stripped by the normalizer: the canonical is the page');
        self::assertSame(['ok:-:' . self::A, 'skipped:non_canonical:' . self::B, 'skipped:non_canonical:https://www.example.com/c'], self::outcome($results));
        self::assertSame('canonical is https://www.example.com/b-canonical', $results[1]->error);
        self::assertSame('canonical is https://other.example.org/c', $results[2]->error);

        $transport = $pages();
        $logger = new ArrayLogger();
        [$submitter] = Factory::submitters($transport, ['non_canonical' => 'replace'], logger: $logger);
        $results = $submitter->submit($urls);
        self::assertSame([self::A, 'https://www.example.com/b-canonical'], self::posted($transport));
        self::assertSame(['ok:-:' . self::A, 'ok:-:https://www.example.com/b-canonical', 'skipped:non_canonical:' . self::B, 'skipped:non_canonical:https://www.example.com/c'], self::outcome($results));
        self::assertSame('canonical is https://www.example.com/b-canonical: submitted instead', $results[1]->error);
        self::assertSame('canonical is https://other.example.org/c: canonical points to a foreign host', $results[2]->error);
        self::assertContains('indexnow verify: replaced https://www.example.com/b with canonical https://www.example.com/b-canonical', $logger->messages('info'));
        self::assertNotContains('https://www.example.com/b-canonical', $transport->gets, 'the canonical is submitted, not verified again');
    }

    #[TestDox('robots.txt: one GET per host, shared through the PSR-16 cache; a 500 robots.txt allows with one warning')]
    public function testRobots(): void
    {
        $cache = new ArrayCache();
        $transport = Factory::transport()
            ->onGet(self::ROBOTS, new Response(200, "User-agent: *\nDisallow: /b"))
            ->onGet(self::A, new Response(200))
            ->onGet('https://de.example.com/robots.txt', new Response(500))
            ->onGet('https://de.example.com/x', new Response(200))
            ->onGet('https://de.example.com/y', new Response(200));
        $logger = new ArrayLogger();
        [$submitter] = Factory::submitters($transport, [], ['hosts' => ['www.example.com' => Factory::KEY, 'de.example.com' => Factory::KEY]], $logger, robotsCache: $cache);

        $submitter->submit([self::A, self::B, 'https://de.example.com/x']);
        $submitter->submit(['https://de.example.com/y']);

        self::assertSame([self::ROBOTS, self::A, 'https://de.example.com/robots.txt', 'https://de.example.com/x', 'https://de.example.com/y'], $transport->gets, 'robots.txt once per host across calls; a disallowed URL is not fetched');
        self::assertCount(3, $transport->posts, 'one POST per host and call');
        self::assertSame("User-agent: *\nDisallow: /b", $cache->values['indexnowkit_robots.www.example.com']);
        self::assertSame(['indexnow verify: robots.txt of de.example.com unavailable (HTTP 500), URLs are treated as allowed'], $logger->messages('warning'), implode("\n", $logger->messages()));
    }

    #[TestDox('a batch above verify.max_batch is sent unverified with one warning')]
    public function testMaxBatch(): void
    {
        $transport = Factory::transport();
        $logger = new ArrayLogger();
        [$submitter] = Factory::submitters($transport, ['max_batch' => 2], logger: $logger);

        $results = $submitter->submit([self::A, self::B, 'https://www.example.com/c']);

        self::assertSame([], $transport->gets);
        self::assertCount(3, self::posted($transport));
        self::assertCount(1, $results);
        self::assertSame(['indexnow verify: batch of 3 URLs exceeds verify.max_batch (2), sent unverified; use sitemap --no-verify or raise the limit'], $logger->messages('warning'));
    }

    #[TestDox('verify.time_budget: once the budget is spent the remaining URLs are sent unverified with one warning')]
    public function testTimeBudget(): void
    {
        $transport = Factory::transport();
        $clock = new FrozenClock();
        $transport->beforeGet = static function (string $url) use ($clock): void {
            if (!str_ends_with($url, '/robots.txt')) {
                $clock->advance(40); // every page GET takes 40 s
            }
        };
        $logger = new ArrayLogger();
        [$submitter] = Factory::submitters($transport, ['time_budget' => 60], logger: $logger, clock: $clock);

        $submitter->submit([self::A, self::B, 'https://www.example.com/c']);

        self::assertCount(2, array_filter($transport->gets, static fn(string $u): bool => !str_ends_with($u, '/robots.txt')), 'A and B are fetched (80 s), C is past the budget');
        self::assertCount(3, self::posted($transport), 'every URL is submitted');
        self::assertSame(['indexnow verify: verify.time_budget of 60 s spent after 2 of 3 URLs; the remaining 1 sent unverified'], $logger->messages('warning'));
    }

    #[TestDox('enabled: false is a transparent delegate: zero GETs, the listeners still see every result')]
    public function testDisabled(): void
    {
        $transport = Factory::transport();
        [$submitter] = Factory::submitters($transport, ['enabled' => false]);
        $seen = [];
        $submitter->addListener(static function (Result $r) use (&$seen): void {
            $seen[] = $r;
        });

        $results = $submitter->submit([self::A]);

        self::assertSame([], $transport->gets);
        self::assertSame([self::A], self::posted($transport));
        self::assertSame($results, $seen);
        self::assertSame([self::A], $submitter->prepare([self::A, self::A]));
    }

    #[TestDox('the notification pipeline: every Result reaches the listeners, PSR-14 and the store exactly once — inner ones not twice, skipped ones not zero times')]
    public function testNotificationsExactlyOnce(): void
    {
        $transport = Factory::transport()
            ->onGet(self::A, new Response(200))
            ->onGet(self::B, new Response(200, '<head><meta name="robots" content="noindex"></head>'));
        $events = new RecordingEvents();
        $store = new RecordingStore();
        [$submitter] = Factory::submitters($transport, [], [], null, $events, $store);
        $seen = [];
        $submitter->addListener(static function (Result $r) use (&$seen): void {
            $seen[] = $r;
        });

        $results = $submitter->submit([self::A, self::B, 'ftp://www.example.com/x']);

        self::assertSame(['ok:-:' . self::A, 'skipped:invalid_url:ftp://www.example.com/x', 'skipped:noindex:' . self::B], self::outcome($results));
        self::assertSame($results, $seen, 'listeners: every result, once, in order');
        self::assertSame(self::outcome($results), self::outcome($events->events), 'PSR-14: every result, once');
        self::assertSame(self::outcome($results), self::outcome(array_map(static fn($r): Result => $r->result, $store->records)), 'store: every result, once');
    }

    #[TestDox('verify.delay waits before the first GET outside a web request only, and not for a batch with nothing to verify')]
    public function testDelay(): void
    {
        $slept = [];
        $sleep = static function (int $s) use (&$slept): void {
            $slept[] = $s;
        };
        $transport = Factory::transport()->onGet(self::A, new Response(200));

        [$submitter] = Factory::submitters($transport, ['delay' => 3], ['strict_hosts' => true], sleep: $sleep);
        $submitter->submit([self::A]);
        self::assertSame([3], $slept);

        $submitter->submit(['https://unmanaged.example.org/x']);
        self::assertSame([3], $slept, 'nothing to verify (no key for the host): no wait');

        [$web] = Factory::submitters($transport, ['delay' => 3], inWebRequest: true, sleep: $sleep);
        $web->submit([self::A]);
        self::assertSame([3], $slept, 'inside a web request the delay is ignored');
    }

    #[TestDox('dry_run: the pre-flight runs (a GET is harmless) so submit --dry-run shows what would be skipped')]
    public function testDryRunStillVerifies(): void
    {
        $transport = Factory::transport()->onGet(self::A, new Response(200, '<head><meta name="robots" content="noindex"></head>'))->onGet(self::B, new Response(200));
        [$submitter] = Factory::submitters($transport, [], ['dry_run' => true]);

        $results = $submitter->submit([self::A, self::B]);

        self::assertSame(['skipped:dry_run:' . self::B, 'skipped:noindex:' . self::A], self::outcome($results));
        self::assertSame([], $transport->posts);
    }

    #[TestDox('URLs of hosts without a key are not fetched: the inner submitter reports them as no_key')]
    public function testUnmanagedHostIsNotFetched(): void
    {
        $transport = Factory::transport()->onGet(self::A, new Response(200));
        [$submitter] = Factory::submitters($transport, [], ['strict_hosts' => true]);

        $results = $submitter->submit([self::A, 'https://unmanaged.example.org/x']);

        self::assertSame(['ok:-:' . self::A, 'skipped:no_key:https://unmanaged.example.org/x'], self::outcome($results));
        self::assertNotContains('https://unmanaged.example.org/x', $transport->gets);
    }
}
