<?php

declare(strict_types=1);

namespace IndexNowKit\Verify;

/**
 * RFC 3986 §5.2 reference resolution, the part a `Location` header or a `<link rel="canonical" href>` needs:
 * absolute references pass, scheme-relative, absolute-path, query-only, fragment-only and relative-path references
 * are resolved against the page URL. No dependency, no `ext-intl`.
 */
final class UrlReference
{
    private function __construct() {}

    /** The absolute URL $reference names when read on the page $base; null when neither can be parsed. */
    public static function resolve(string $base, string $reference): ?string
    {
        $reference = trim($reference);
        if ($reference === '') {
            return null;
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $reference) === 1) {
            return $reference;
        }
        $b = parse_url($base);
        if (!\is_array($b) || !isset($b['scheme'], $b['host'])) {
            return null;
        }
        $authority = $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
        if (str_starts_with($reference, '//')) {
            return $b['scheme'] . ':' . $reference;
        }
        $basePath = $b['path'] ?? '/';
        if (str_starts_with($reference, '#')) {
            return $b['scheme'] . '://' . $authority . $basePath . (isset($b['query']) ? '?' . $b['query'] : '');
        }
        if (str_starts_with($reference, '?')) {
            return $b['scheme'] . '://' . $authority . $basePath . $reference;
        }
        $r = parse_url($reference);
        if (!\is_array($r)) {
            return null;
        }
        $path = $r['path'] ?? '';
        if (!str_starts_with($path, '/')) {
            $dir = str_contains($basePath, '/') ? substr($basePath, 0, (int) strrpos($basePath, '/') + 1) : '/';
            $path = $dir . $path;
        }

        return $b['scheme'] . '://' . $authority . self::removeDotSegments($path) . (isset($r['query']) ? '?' . $r['query'] : '');
    }

    /** RFC 3986 §5.2.4 (empty segments collapse too: `/a//b` is `/a/b`). */
    private static function removeDotSegments(string $path): string
    {
        $out = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '.' || $segment === '') {
                continue;
            }
            if ($segment === '..') {
                array_pop($out);

                continue;
            }
            $out[] = $segment;
        }
        $trailingSlash = $out !== [] && (str_ends_with($path, '/') || str_ends_with($path, '/.') || str_ends_with($path, '/..'));

        return '/' . implode('/', $out) . ($trailingSlash ? '/' : '');
    }
}
