# Changelog

Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Versioning: SemVer; until 1.0 minor versions may
contain breaking changes, listed under "Changed". What the compatibility promise covers: [docs/bc.md](docs/bc.md).

## [0.3.0] — 2026-09-07

### Added

- **`Verify\Adapter\VerifyServices`** — what every framework adapter wires for this package, in one place: `package()`,
  `options()`, `config()`, the pre-flight `transport()`, the `robots()` cache, the decorated `submitter()` and
  `submitterFactory()`, the `check` lines with their texts (`installedLine()`, `installedCheck()`, `dispatchCheck()`,
  `transportCheck()`, `sampleCheck()`), and the `*For()` twins over the core's runtime graph (`Adapter\Services`:
  `transportFor()`, `robotsFor()`, `submitterFor()`, `submitterFactoryFor()`, `checksFor()`). The Symfony bundle, the
  Laravel and the Yii2 adapters build on it (their copies of the texts and constructions are gone); the next adapter
  keeps only what its framework decides.

## [0.2.1] — 2026-09-07

### Changed

- Requires `indexnowkit/core ^0.12`.

## [0.2.0] — 2026-09-07

### Changed

- **`X-Robots-Tag` parsing**: a bot prefix applies to its own directive only. `googlebot: noindex, noindex` (two headers
  joined by the header line) hid the second, global `noindex`, and the page was submitted.
- **`RobotsCache` keys by origin** (`robots.<scheme>_<host>_<port>` for anything but https on 443): staging on
  `http://host:8080` and production on `https://host` no longer share one `robots.txt` for an hour.
- The pre-flight transport reads at most 1 MiB of a page (`VerifyConfig::BODY_LIMIT`) instead of the 50 MiB of the core's
  GET limit; the signals live in the first 256 KiB.
- Requires `indexnowkit/core ^0.11`.

### Added

- **`verify.time_budget`** (60 s): the pre-flight of one batch stops when the budget is spent and the remaining URLs are
  sent unverified with one warning, so a queue job never runs past its visibility timeout into a second worker.
- **`Check\DispatchCheck`** (`verify.dispatch`, with the adapter's queue mode) and **`Check\TransportCheck`**
  (`verify.transport`: an application `http.client` keeps its own settings, so `verify.redirect`, `verify.max_redirects`,
  `verify.timeout` and the host check on redirect targets may not apply) — the adapters register both.

## [0.1.1] — 2026-09-06

### Changed

- Requires `indexnowkit/core ^0.10` (`Attribute\ParamExtractor` became an injected object; nothing else in the core changed).

## [0.1.0] — 2026-09-06

First release (spec 17 §6.1, wave F). Requires `indexnowkit/core ^0.9`.

### Added

- **`Verify\VerifyingSubmitter`** — the pre-flight decorator of `SubmitterInterface`: one GET per URL before the
  decorated submitter sends it. `noindex` (`X-Robots-Tag`, `<meta name="robots">`), a `robots.txt` disallow, another
  canonical URL, a redirect and an origin error become skipped `Result`s with `Reason::Noindex`, `RobotsDisallowed`,
  `NonCanonical`, `Redirected`, `OriginError`; 404 and 410 pass as deletions. Policies `verify.redirect: skip|follow`
  (after a 301/308 both URLs are submitted, after a 302/303/307 the original), `verify.non_canonical: skip|replace`,
  `verify.origin_error: skip|send`; a redirect or a canonical to a host without a key is always a skip. Listeners
  registered on the decorator see every result exactly once; the skipped results go to the PSR-14 dispatcher and the
  submission store. `verify.max_batch` (100) sends larger batches unverified with a warning; `verify.delay` waits
  before the first GET outside a web request; `enabled: false` (the default) is a transparent delegate.
- **`Verify\PageSignals`** — the parser of the signals (`fromResponse(url, Response)`: `status`, `noindex`,
  `noindexSource`, `canonical`, `location`, `contentType`), regular expressions over the `<head>` of the first
  `MAX_BYTES` (256 KiB), no `ext-dom`; **`Verify\UrlReference::resolve()`** for relative `href` and `Location` values.
- **`Verify\RobotsCache`** — `robots.txt` once per host and process, shared through the PSR-16 cache under
  `<debounce.key_prefix>robots.<host>` (`verify.robots_cache_ttl`); an unavailable `robots.txt` allows with one warning.
- **`Verify\VerifyConfig`** — the `verify` block (`OPTIONS`, `fromArray()`, `disabled()`, `loadOrDisabled()`,
  `transportConfig(Config)`, `userAgent()`), the enums `RedirectPolicy`, `NonCanonicalPolicy`, `OriginErrorPolicy`.
- **`Verify\Check\SampleCheck`** — `check --sample=<url>` / `--sample-class=<FQCN>[:<id>]`: one line per sample
  (`verify.sample`), warnings only.
- Documents: `docs/configuration.md`, `docs/operations.md` (the fixed log lines), `docs/bc.md`.
