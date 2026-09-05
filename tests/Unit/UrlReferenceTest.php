<?php

declare(strict_types=1);

namespace IndexNowKit\Verify\Tests\Unit;

use IndexNowKit\Verify\UrlReference;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UrlReferenceTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: string, 2: string|null}>
     */
    public static function references(): iterable
    {
        $base = 'https://www.example.com:8443/a/b/c?q=1#f';
        yield 'absolute' => [$base, 'http://other.example.com/x', 'http://other.example.com/x'];
        yield 'scheme-relative' => [$base, '//cdn.example.com/x', 'https://cdn.example.com/x'];
        yield 'absolute path' => [$base, '/x/y', 'https://www.example.com:8443/x/y'];
        yield 'relative' => [$base, 'd', 'https://www.example.com:8443/a/b/d'];
        yield 'relative with query' => [$base, 'd?z=2', 'https://www.example.com:8443/a/b/d?z=2'];
        yield 'dot' => [$base, './d', 'https://www.example.com:8443/a/b/d'];
        yield 'dot dot' => [$base, '../d', 'https://www.example.com:8443/a/d'];
        yield 'too many dot dots' => [$base, '../../../d', 'https://www.example.com:8443/d'];
        yield 'trailing slash kept' => [$base, 'd/', 'https://www.example.com:8443/a/b/d/'];
        yield 'query only' => [$base, '?z=2', 'https://www.example.com:8443/a/b/c?z=2'];
        yield 'fragment only' => [$base, '#g', 'https://www.example.com:8443/a/b/c?q=1'];
        yield 'empty' => [$base, '   ', null];
        yield 'base without path' => ['https://www.example.com', 'x', 'https://www.example.com/x'];
        yield 'base is not a URL' => ['not a url', 'x', null];
        yield 'mailto is absolute' => [$base, 'mailto:a@b', 'mailto:a@b'];
        yield 'double slash in the path collapses' => [$base, '/a//b', 'https://www.example.com:8443/a/b'];
    }

    #[DataProvider('references')]
    public function testResolve(string $base, string $reference, ?string $expected): void
    {
        self::assertSame($expected, UrlReference::resolve($base, $reference));
    }
}
