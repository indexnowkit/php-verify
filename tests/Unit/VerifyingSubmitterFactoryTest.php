<?php

declare(strict_types=1);

namespace IndexNowKit\Verify\Tests\Unit;

use IndexNowKit\Adapter\SubmitterFactory;
use IndexNowKit\Debounce\MemoryDebounceStore;
use IndexNowKit\Http\Response;
use IndexNowKit\Key\StaticKeyProvider;
use IndexNowKit\Testing\FakeTransport;
use IndexNowKit\Throttle\NullThrottle;
use IndexNowKit\Url\UrlNormalizerFactory;
use IndexNowKit\Verify\Tests\Support\Factory;
use IndexNowKit\Verify\VerifyConfig;
use IndexNowKit\Verify\VerifyingSubmitter;
use IndexNowKit\Verify\VerifyingSubmitterFactory;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

final class VerifyingSubmitterFactoryTest extends TestCase
{
    #[TestDox('every submitter of the decorated factory verifies: --dry-run still fetches, --force still skips a noindex page')]
    public function testDecorates(): void
    {
        $config = Factory::config();
        $keys = StaticKeyProvider::fromConfig($config);
        $normalizer = UrlNormalizerFactory::fromConfig($config);
        $transport = (new FakeTransport())
            ->onGet('https://www.example.com/a', new Response(200))
            ->onGet('https://www.example.com/b', new Response(200, '<head><meta name="robots" content="noindex"></head>'));
        $inner = new SubmitterFactory($transport, $keys, $config, new MemoryDebounceStore(), new NullThrottle(), $normalizer);
        $factory = new VerifyingSubmitterFactory($inner, $transport, VerifyConfig::fromArray(['enabled' => true]), $keys, $normalizer);

        $dry = $factory->create(false, true);
        self::assertInstanceOf(VerifyingSubmitter::class, $dry);
        $results = $dry->submit(['/a', '/b']);
        self::assertSame(['dry_run', 'noindex'], array_map(static fn($r): string => (string) $r->reason?->value, $results));
        self::assertSame([], $transport->posts);

        $forced = $factory->create(true, false);
        $results = $forced->submit(['/a', '/b']);
        self::assertSame(['', 'noindex'], array_map(static fn($r): string => (string) $r->reason?->value, $results));
        self::assertCount(1, $transport->posts);
    }
}
