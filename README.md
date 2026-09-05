# IndexNow pre-flight check — `indexnowkit/verify`

One GET of every URL before it is sent to Yandex, Bing and the other [IndexNow](https://www.indexnow.org) engines. A
page that says `noindex`, that `robots.txt` disallows, that names another canonical URL, that redirects, or that the
origin does not serve, is not submitted; each becomes a skipped `Result` with a stable `Reason`
(`noindex`, `robots_disallowed`, `non_canonical`, `redirected`, `origin_error`) in the log, the listeners, the PSR-14
events and the submission store. The engines see only URLs worth crawling — announcing a `noindex` page or a redirect
is at best wasted quota and at worst a reason for an engine to trust the key less.

**What it never blocks: 404 and 410.** A URL that answers 404 or 410 is submitted as is — that is the deletion signal
IndexNow accepts, and the reason to submit a removed page in the first place. IndexNow is a notification, not indexing:
the engine decides whether and when to crawl.

**Off by default.** Installing the package changes nothing until `verify.enabled: true`.

[![Packagist](https://img.shields.io/packagist/v/indexnowkit/verify)](https://packagist.org/packages/indexnowkit/verify)
[![Downloads](https://img.shields.io/packagist/dt/indexnowkit/verify)](https://packagist.org/packages/indexnowkit/verify)
[![CI](https://github.com/indexnowkit/php/actions/workflows/ci.yml/badge.svg)](https://github.com/indexnowkit/php/actions)
![Coverage](https://img.shields.io/badge/coverage-%E2%89%A5%2090%25%20enforced-brightgreen)
![PHPStan](https://img.shields.io/badge/phpstan-level%209-4c1)
![PHP](https://img.shields.io/badge/php-%5E8.2-777bb4)
[![License](https://img.shields.io/packagist/l/indexnowkit/verify)](LICENSE)

[Русская версия](README.ru.md) · Issues and pull requests: [github.com/indexnowkit/php](https://github.com/indexnowkit/php/issues) (the `php-*` repositories are read-only splits)

## Install

```bash
composer require indexnowkit/verify         # brings indexnowkit/core; nothing else
```

With a framework adapter (`indexnowkit/symfony-bundle`, `laravel`, `yii2`) that is all: the adapter finds the package,
adds the `verify` block to its configuration and, once `verify.enabled` is `true`, decorates its submitter — the
queue, Messenger and yii2-queue workers verify too, because they get the same submitter. `check` prints one line
about it either way (`verify: installed, disabled (verify.enabled: false)`).

```yaml
# Symfony: config/packages/indexnowkit.yaml          # Laravel: config/indexnow.php 'verify' => [...]
indexnowkit:                                         # Yii2: 'verify' => [...] of the component
    verify:
        enabled: true
        redirect: skip           # skip | follow
        non_canonical: skip      # skip | replace
        origin_error: skip       # skip | send
```

## What one GET decides

| The URL answers | Decision |
|---|---|
| `200` with `X-Robots-Tag: noindex` / `none` (no bot prefix, or an IndexNow engine's bot; `googlebot:` is another engine) or `<meta name="robots" content="noindex">` in `<head>` | skipped, `Reason::Noindex` |
| `200` and `robots.txt` of the host disallows the path for `*` or an engine's bot (checked before the GET) | skipped, `Reason::RobotsDisallowed` |
| `200` with `Link: <…>; rel="canonical"` or `<link rel="canonical">` naming another URL (after the core's normalization: tracking parameters, trailing slash) | `non_canonical: skip` → skipped, `Reason::NonCanonical`; `replace` → the canonical is submitted instead, when its host is one of yours |
| `3xx` | `redirect: skip` → skipped, `Reason::Redirected`; `follow` → the chain is followed (`max_redirects`, http(s), your hosts only) and the target verified; after a `301`/`308` both URLs are submitted (the page moved), after a `302`/`303`/`307` only the original |
| `404`, `410` | **submitted** — a deletion |
| `401`, `403`, `5xx`, any other `4xx`, a timeout or connection failure | `origin_error: skip` → skipped, `Reason::OriginError` (retryable: a queue may try again); `send` → submitted with a warning. A `401`/`403` on a protected environment means: set `verify.enabled: false` there |

The GET is a real GET (not HEAD: many origins answer HEAD differently), without following redirects, with
`verify.timeout` (5 s) and its own `User-Agent` (`verify.user_agent`). Only the `<head>` of the first 256 KiB is read
for the signals; a body that is not `text/html` / `application/xhtml+xml` contributes headers only. Comments,
attribute order and case do not matter.

`robots.txt` is fetched once per host and process and kept in the PSR-16 cache behind `debounce.store`
(`verify.robots_cache_ttl`, an hour). A `robots.txt` that cannot be fetched (not `200`, not `404`) blocks nothing: one
warning per host, every path is treated as allowed — an unreachable `robots.txt` is no reason to keep quiet.

**Batches.** A batch above `verify.max_batch` (100) URLs is sent unverified with one warning: that is the `sitemap`
command, where the site itself lists its URLs (`indexnow:sitemap --no-verify` says so explicitly). `submit --dry-run`
runs the pre-flight (a GET is harmless) so it shows what would be skipped.

## `dispatch: sync` and `verify.delay`

With `dispatch: sync` the pre-flight GETs run inside the web request that changed the page: N URLs cost N round trips
to your own origin, and on a single-process development server (`symfony server:start` without workers, `php artisan
serve`, `php yii serve`) a request to yourself hangs until the timeout. `check` warns about it (`verify.dispatch`).
Use `dispatch: queue` / `messenger`, or keep `verify.enabled: false` outside production.

`verify.delay` (seconds, at most 30) waits before the first GET of a batch, so a queue worker that runs right after
the commit does not fetch the page from a cache that still holds the old version; it applies only outside a web
request and is ignored with `dispatch: sync`.

## `check --sample`

```bash
bin/console indexnow:check --sample=https://www.example.com/blog/post-1 --sample-class='App\Entity\Post'
php artisan indexnow:check --sample=https://www.example.com/blog/post-1 --sample-class='App\Models\Post:42'
php yii indexnow/check --sample=https://www.example.com/blog/post-1,https://www.example.com/    # Yii2: comma-separated, no comma inside a URL
```

Every sample is fetched and reported as one line (`verify sample https://…: HTTP 200, index, canonical: self, robots:
allowed`, code `verify.sample`, the host of the URL). `--sample-class` takes a class annotated with `#[IndexNow]`
(`<FQCN>` — up to three objects of it, `<FQCN>:<id>` — that one) and resolves their URLs the way the submission does. A
`noindex`, a disallow, another canonical, a redirect, a `4xx`/`5xx` or an unreachable origin is a **warning, never an
error**: production may be unreachable from CI, and the report must not fail a deploy over it. Without the package
`--sample` is an error (`check --sample needs indexnowkit/verify`), `check` without samples prints a `no sample given` line (code
`verify.sample`).

## Plain PHP

```php
use IndexNowKit\Config;
use IndexNowKit\Http\TransportFactory;
use IndexNowKit\IndexNowKit;
use IndexNowKit\Key\StaticKeyProvider;
use IndexNowKit\Url\UrlNormalizerFactory;
use IndexNowKit\Verify\VerifyConfig;
use IndexNowKit\Verify\VerifyingSubmitter;

$core = Config::fromEnv();
$verify = VerifyConfig::fromArray(['enabled' => true, 'redirect' => 'follow']);
$indexNow = IndexNowKit::create($core);
$submitter = new VerifyingSubmitter(
    $indexNow->submitter,
    TransportFactory::lazy($verify->transportConfig($core), null, ['User-Agent' => $verify->userAgent()]),
    $verify,
    StaticKeyProvider::fromConfig($core),
    UrlNormalizerFactory::fromConfig($core),
);
$results = $submitter->submit(['https://www.example.com/blog/post-1']);
```

`VerifyingSubmitter` implements `SubmitterInterface`; listeners registered on it see every result (the inner ones and
the skipped ones) exactly once; pass the same PSR-14 dispatcher and submission store you gave the inner submitter so
the skipped results reach them too. The log lines are fixed strings operators can grep
([docs/operations.md](docs/operations.md)); the options are in [docs/configuration.md](docs/configuration.md).

## Requirements

PHP 8.2+, `indexnowkit/core ^0.9`; `indexnowkit/console ^0.3` for the `--sample` options of the `check` command (every
adapter has it).

## Notes for AI assistants

- Composer package `indexnowkit/verify` (add-on of `indexnowkit/core`). With a framework adapter nothing is wired by hand: `verify.enabled: true` in the adapter's configuration decorates the submitter; the workers of the queue verify too.
- Minimal complete snippet (every `use` included):

```php
use IndexNowKit\Config;
use IndexNowKit\Http\TransportFactory;
use IndexNowKit\IndexNowKit;
use IndexNowKit\Key\StaticKeyProvider;
use IndexNowKit\Url\UrlNormalizerFactory;
use IndexNowKit\Verify\VerifyConfig;
use IndexNowKit\Verify\VerifyingSubmitter;

$core = Config::fromEnv();
$verify = VerifyConfig::fromArray(['enabled' => true]);
$indexNow = IndexNowKit::create($core);
$submitter = new VerifyingSubmitter($indexNow->submitter, TransportFactory::lazy($verify->transportConfig($core), null, ['User-Agent' => $verify->userAgent()]), $verify, StaticKeyProvider::fromConfig($core), UrlNormalizerFactory::fromConfig($core));
$submitter->submit(['https://www.example.com/page']);    // skipped Results carry Reason::Noindex, RobotsDisallowed, NonCanonical, Redirected, OriginError
```

- Verify: `bin/console indexnow:check --sample=https://www.example.com/page`, `php artisan indexnow:check --sample=…`, `php yii indexnow/check --sample=…` — one line per sample, warnings only; `check` alone prints the `verify:` line (installed / disabled / not installed) and, with the package, a "no sample given" line.
- Pitfalls:
  - `verify.enabled` is `false` by default; installing changes nothing. With `dispatch: sync` the GETs run inside the web request (`check` warns): use a queue.
  - 404 and 410 pass on purpose (deletions); `verify.redirect: follow` submits both URLs after a 301/308, only the original after a 302/303/307.
  - A canonical or a redirect target on a host without a key in `hosts` / `base_url` is a skip, never a submission: a page cannot make you announce another site.
  - robots.txt unavailable (500, timeout) allows everything with one warning; 404 is the normal "no robots.txt".
  - Batches above `verify.max_batch` (100) go unverified with a warning — that is the `sitemap` command; `indexnow:sitemap --no-verify` says it explicitly.
  - `indexnow:check --sample` needs this package; without it the option is an error with the install line.
  - `dispatch: auto` exists in Symfony and Yii2, **not** in Laravel; locales are `router.locales` (Laravel), `router.languages` (Yii2), `framework.enabled_locales` (Symfony).

## Versioning

SemVer; until 1.0 minor versions may contain breaking changes, listed in [CHANGELOG.md](CHANGELOG.md). What the
compatibility promise covers: [docs/bc.md](docs/bc.md).

MIT. IndexNow is a trademark of its owner; this project is independent and not affiliated with Microsoft, Yandex or indexnow.org.
