<?php

declare(strict_types=1);

namespace IndexNowKit\Verify;

use IndexNowKit\Http\Response;

/**
 * What one GET of a page tells about its indexability: `noindex` from `X-Robots-Tag` or `<meta name="robots">`, the
 * canonical URL from `Link: <…>; rel="canonical"` or `<link rel="canonical">`, the `Location` of a redirect. Regular
 * expressions over the `<head>` of the first {@see MAX_BYTES} of the body, no `ext-dom`: the signals sit in the
 * head of every real page, and a parser that tolerates broken markup is what a crawler runs too.
 */
final readonly class PageSignals
{
    /** Bytes of the body read for the signals; a `<head>` longer than this is a page nobody indexes anyway. */
    public const MAX_BYTES = 262_144;

    public const SOURCE_HEADER = 'X-Robots-Tag';
    public const SOURCE_META = 'meta robots';

    /** Bots of the IndexNow engines in an `X-Robots-Tag: bot: …` prefix or a `<meta name="bot">`; Googlebot is not one. */
    private const ENGINE_BOTS = '/bing|msnbot|yandex|seznam|naver|yeti|amazon|ia_archiver|archive/i';
    /** Directives that carry a colon themselves, so `max-snippet:20` is not read as a bot prefix. */
    private const COLON_DIRECTIVES = ['unavailable_after', 'max-snippet', 'max-image-preview', 'max-video-preview'];

    /**
     * @param int         $status        HTTP status of the answer
     * @param bool        $noindex       the page asks every engine (or an IndexNow engine's bot) not to index it
     * @param string|null $noindexSource {@see SOURCE_HEADER} or {@see SOURCE_META} when $noindex
     * @param string|null $canonical     absolute canonical URL the page declares, when it declares one
     * @param string|null $location      absolute `Location` of a 3xx answer, when present
     * @param string|null $contentType   media type of the body (`text/html`), when the transport exposes headers
     */
    public function __construct(
        public int $status,
        public bool $noindex = false,
        public ?string $noindexSource = null,
        public ?string $canonical = null,
        public ?string $location = null,
        public ?string $contentType = null,
    ) {}

    /**
     * @param string $url the URL that was fetched: the `Location` and a `Link:` header resolve against it, a relative
     *                    `href` in the document against its `<base href>` when it has one ({@see baseHref()})
     */
    public static function fromResponse(string $url, Response $response): self
    {
        $location = $response->header('location');
        $contentType = $response->contentType();
        [$noindex, $source] = self::headerNoindex($response->header('x-robots-tag'));
        $canonical = self::headerCanonical($url, $response->header('link'));

        $isHtml = $contentType === null ? true : \in_array($contentType, ['text/html', 'application/xhtml+xml'], true);
        if ($isHtml && $response->body !== '') {
            $head = self::head($response->body);
            if (!$noindex) {
                [$noindex, $source] = self::metaNoindex($head);
            }
            $canonical ??= self::linkCanonical(self::baseHref($url, $head), $head);
        }

        return new self(
            status: $response->status,
            noindex: $noindex,
            noindexSource: $source,
            canonical: $canonical,
            location: $location === null ? null : UrlReference::resolve($url, $location),
            contentType: $contentType,
        );
    }

    /** The `<head>` (up to `</head>` or `<body`) of the first MAX_BYTES, comments removed. */
    private static function head(string $body): string
    {
        $head = substr($body, 0, self::MAX_BYTES);
        if (preg_match('#</head\s*>|<body[\s>]#i', $head, $m, PREG_OFFSET_CAPTURE) === 1) {
            $head = substr($head, 0, $m[0][1]);
        }

        return (string) preg_replace('/<!--.*?(-->|$)/s', '', $head);
    }

    /**
     * `X-Robots-Tag: noindex`, `…: none`, `bingbot: noindex, nofollow`; a `googlebot:` prefix addresses another engine.
     *
     * @return array{0: bool, 1: string|null}
     */
    private static function headerNoindex(?string $header): array
    {
        if ($header === null) {
            return [false, null];
        }
        foreach (explode(',', $header) as $token) {
            $bot = null; // per token: `googlebot: noindex, noindex` addresses Google, then everybody
            $token = strtolower(trim($token));
            if (str_contains($token, ':') && !\in_array(strtolower(trim(explode(':', $token, 2)[0])), self::COLON_DIRECTIVES, true)) {
                [$bot, $token] = array_map('trim', explode(':', $token, 2));
            }
            if (($token === 'noindex' || $token === 'none') && ($bot === null || $bot === '*' || preg_match(self::ENGINE_BOTS, $bot) === 1)) {
                return [true, self::SOURCE_HEADER];
            }
        }

        return [false, null];
    }

    /** `Link: <https://…>; rel="canonical"` (several links comma-separated, several rel values space-separated). */
    private static function headerCanonical(string $url, ?string $header): ?string
    {
        if ($header === null) {
            return null;
        }
        foreach (self::split('/,\s*(?=<)/', $header) as $link) {
            if (preg_match('/^\s*<([^>]+)>(.*)$/s', $link, $m) !== 1) {
                continue;
            }
            if (preg_match('/;\s*rel\s*=\s*(?:"([^"]*)"|([^;\s]+))/i', $m[2], $rel) !== 1) {
                continue;
            }
            $values = self::split('/\s+/', strtolower(trim($rel[1] !== '' ? $rel[1] : ($rel[2] ?? ''))));
            if (\in_array('canonical', $values, true)) {
                return UrlReference::resolve($url, $m[1]);
            }
        }

        return null;
    }

    /**
     * @return array{0: bool, 1: string|null}
     */
    private static function metaNoindex(string $head): array
    {
        if (preg_match_all('/<meta\b[^>]*>/i', $head, $tags) === 0) {
            return [false, null];
        }
        foreach ($tags[0] as $tag) {
            $attributes = self::attributes($tag);
            $name = strtolower($attributes['name'] ?? '');
            if ($name === '' || ($name !== 'robots' && preg_match(self::ENGINE_BOTS, $name) !== 1)) {
                continue;
            }
            foreach (explode(',', strtolower($attributes['content'] ?? '')) as $directive) {
                $directive = trim($directive);
                if ($directive === 'noindex' || $directive === 'none') {
                    return [true, self::SOURCE_META];
                }
            }
        }

        return [false, null];
    }

    /**
     * What a relative `href` of the document resolves against: the first `<base href>` of the head (itself resolved
     * against the page URL, as HTML requires), or the page URL when there is none. A page with `<base href="/en/">`
     * and `<link rel="canonical" href="page">` is canonical at `/en/page`, and the pre-flight must read the same URL
     * the crawler does — otherwise it drops the page as non-canonical.
     */
    private static function baseHref(string $url, string $head): string
    {
        if (preg_match('/<base\b[^>]*>/i', $head, $tag) !== 1) {
            return $url;
        }
        $href = self::attributes($tag[0])['href'] ?? '';

        return ($href === '' ? null : UrlReference::resolve($url, html_entity_decode($href, ENT_QUOTES | ENT_HTML5))) ?? $url;
    }

    private static function linkCanonical(string $url, string $head): ?string
    {
        if (preg_match_all('/<link\b[^>]*>/i', $head, $tags) === 0) {
            return null;
        }
        foreach ($tags[0] as $tag) {
            $attributes = self::attributes($tag);
            $rel = self::split('/\s+/', strtolower(trim($attributes['rel'] ?? '')));
            if (\in_array('canonical', $rel, true) && ($attributes['href'] ?? '') !== '') {
                return UrlReference::resolve($url, html_entity_decode($attributes['href'], ENT_QUOTES | ENT_HTML5));
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function split(string $pattern, string $subject): array
    {
        $parts = preg_split($pattern, $subject);

        return $parts === false ? [] : $parts;
    }

    /**
     * @return array<string, string> attribute name (lower-case) => value, quotes removed
     */
    private static function attributes(string $tag): array
    {
        $attributes = [];
        if (preg_match_all('/([a-z][a-z0-9_:-]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+))/i', $tag, $m, PREG_SET_ORDER) > 0) {
            foreach ($m as $attribute) {
                $attributes[strtolower($attribute[1])] = ($attribute[2] ?? '') !== '' ? $attribute[2] : (($attribute[3] ?? '') !== '' ? $attribute[3] : ($attribute[4] ?? ''));
            }
        }

        return $attributes;
    }
}
