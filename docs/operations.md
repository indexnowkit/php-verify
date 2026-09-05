# Operations

## Log lines

Every line of the pre-flight starts with `indexnow verify: `; the prefixes below are fixed (operators grep them, and
`docs/bc.md` lists them as a contract), the values in `{}` are PSR-3 context.

| Level | Line | When |
|---|---|---|
| info | `indexnow verify: skipped {url}: noindex ({source})` | `X-Robots-Tag` or `meta robots` says `noindex` / `none` |
| info | `indexnow verify: skipped {url}: robots.txt disallows ({rule})` | the host's `robots.txt` disallows the path (`{rule}` = `Disallow: /path`) |
| info | `indexnow verify: skipped {url}: canonical is {canonical}` | the page names another canonical, `non_canonical: skip` |
| info | `indexnow verify: skipped {url}: canonical is {canonical}: canonical points to a foreign host` | `non_canonical: replace`, but the canonical's host has no key |
| info | `indexnow verify: replaced {url} with canonical {canonical}` | `non_canonical: replace`: the canonical is submitted instead |
| info | `indexnow verify: skipped {url}: redirected to {location} ({status})` | a 3xx under `redirect: skip`, or without `Location`; with `: redirect leaves the configured hosts`, `: redirect loop`, `: more than {max} redirects` under `follow` |
| info | `indexnow verify: {url} gone (HTTP {status}), submitted as a deletion` | 404 / 410 |
| warning | `indexnow verify: skipped {url}: origin error ({error})` | 401, 403, 5xx, any other 4xx or a transport failure, `origin_error: skip`; `{error}` is `HTTP 503` or the transport message; 401/403 add `the origin refuses the pre-flight GET; on a protected environment set verify.enabled: false` |
| warning | `indexnow verify: {url} origin error ({error}), sent anyway (verify.origin_error: send)` | the same under `origin_error: send` |
| warning | `indexnow verify: batch of {count} URLs exceeds verify.max_batch ({max}), sent unverified; use sitemap --no-verify or raise the limit` | a batch above `verify.max_batch` |
| warning | `indexnow verify: robots.txt of {host} unavailable ({error})`, `… unavailable (HTTP {status}), URLs are treated as allowed` | `robots.txt` not 200 / 404 or a transport failure — once per host and process |
| warning | `indexnow verify: robots cache unavailable, fetching robots.txt per process: {error}` | the PSR-16 cache threw — once per process |
| critical | `indexnow verify: invalid verify configuration, pre-flight checks are off until it is fixed: {error} (run "{check}")` | the `verify` block does not validate |

The skipped results carry the same facts for code: `Result::$reason` (`noindex`, `robots_disallowed`,
`non_canonical`, `redirected`, `origin_error`), `Result::$error` (the sentence after `skipped {url}: `), `engine`
`none`, `retryable` true for `origin_error` only. Metrics by `Result::metricLabels()` count them under `status:
skipped, reason: …`.

## What to alert on

- `origin error` lines in a row for one host: the origin refuses the pre-flight (WAF, auth, maintenance) and nothing
  reaches the engines until it is fixed or `verify.origin_error: send` / `verify.enabled: false` is set.
- `exceeds verify.max_batch` outside a scheduled `sitemap` run: something submits thousands of URLs at once.
- `robots.txt of {host} unavailable`: the pre-flight allows everything; the robots.txt itself is the problem.

## Checks

`indexnow:check` prints, with the package: `verify: installed, disabled (verify.enabled: false)` (ok, `verify.installed`)
or `verify: enabled (redirect: skip, non_canonical: skip, origin_error: skip)` (an invalid block is not a check line:
`VerifyConfig::loadOrDisabled()` logs it at `critical` and the pre-flight is off, the bundle rejects it at compile
time); `verify.dispatch` (warning) for `verify.enabled` with `dispatch: sync`; one `verify.sample` line per `--sample`
/ `--sample-class` (ok or warning, never error), or `verify sample: no sample given (check --sample=<url>)` (ok).
Without the package: `verify: not installed (composer require indexnowkit/verify) — pre-flight checks off` (ok), and
`--sample` is an error with the install line.

## Staging and CI

Outside production the core already refuses to submit without an explicit `dry_run: false`; the pre-flight runs under
`dry_run` too (a GET is harmless) so `submit --dry-run` and `check --sample` show what would be skipped. A staging
behind basic auth answers 401 to the pre-flight: `verify.enabled: false` there, or `verify.origin_error: send`.
