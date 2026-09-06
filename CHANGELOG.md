# Changelog

Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Versioning: SemVer; until 1.0 minor versions may
contain breaking changes, listed under "Changed". What the compatibility promise covers: [docs/bc.md](docs/bc.md).

## [0.1.1] — Unreleased

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
