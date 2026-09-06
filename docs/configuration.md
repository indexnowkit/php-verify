# Configuration — the `verify` block

The block lives next to the core options in the adapter's configuration (`indexnowkit.verify` in the bundle,
`'verify' => [...]` in `config/indexnow.php`, `'verify' => [...]` of the Yii2 component); `VerifyConfig::OPTIONS`
lists its keys in dotted form and the adapters add them to the keys they accept, so a typo warns at boot like a
core typo. In plain PHP: `VerifyConfig::fromArray($block)`.

| Key | Default | Meaning |
|---|---|---|
| `verify.enabled` | `false` | Decorate the submitter with the pre-flight. Off: nothing is fetched, nothing changes. |
| `verify.redirect` | `skip` | `skip`: a 3xx is skipped (`Reason::Redirected`). `follow`: the chain is followed (`max_redirects` hops, http(s), hosts with a key only) and the target verified; after a 301/308 both URLs are submitted, after a 302/303/307 only the original. |
| `verify.non_canonical` | `skip` | `skip`: a page whose canonical is another URL is skipped (`Reason::NonCanonical`). `replace`: the canonical is submitted instead, when its host has a key; a foreign canonical is a skip. |
| `verify.origin_error` | `skip` | `skip`: 401, 403, 5xx, any other 4xx and a transport failure are skipped (`Reason::OriginError`, retryable). `send`: submitted anyway, with a warning. |
| `verify.delay` | `0` | Seconds (0–30) to wait before the first GET of a batch, outside a web request only (a queue worker right after the commit, when a cache may still hold the old page). Ignored with `dispatch: sync`. |
| `verify.timeout` | `5` | Seconds one pre-flight GET may take (`http.timeout` of the pre-flight transport). |
| `verify.max_redirects` | `3` | Hops followed under `redirect: follow`; more is a skip. |
| `verify.max_batch` | `100` | Largest batch verified. A larger one (the `sitemap` command) is sent unverified with one warning; `sitemap --no-verify` says it explicitly. Each URL costs one blocking GET (plus one per redirect hop and one `robots.txt` per origin), sequentially: with `dispatch: sync` that latency lands on the web response. |
| `verify.time_budget` | `60` | Seconds the pre-flight of one batch may take in total. A queue job has a visibility timeout (yii2-queue `ttr`, SQS `visibility_timeout`, Messenger `redeliver_timeout`); a pre-flight running past it is handed to a second worker and the batch is sent twice. When the budget runs out the remaining URLs are sent unverified with one warning. `0` = no budget. |
| `verify.robots_cache_ttl` | `3600` | Seconds a fetched `robots.txt` is kept in the PSR-16 cache behind `debounce.store` under `<debounce.key_prefix>robots.<host>` (`robots.<scheme>_<host>_<port>` for anything but https on 443: staging on another origin has its own file); `0` = per process only. |
| `verify.user_agent` | `indexnowkit-verify/<version> (+https://github.com/indexnowkit/php)` | `User-Agent` of the pre-flight GETs. Allow it in your WAF, or it will see 403s. |

The pre-flight transport is the application's `http.client` when one is configured, else the discovered PSR-18 client
built without redirects and with a 1 MiB body limit (the signals live in the first 256 KiB). **A client the application
hands over keeps its own settings**: one that follows redirects internally makes `verify.redirect`, `verify.max_redirects`
and the host check on redirect targets silent, and `verify.timeout` does not apply — `check` says so (`verify.transport`).
`verify.timeout` replaces `http.timeout` for the discovered client
(`VerifyConfig::transportConfig(Config $core)`). Every other core option (`hosts`, `base_url`, `normalizer.*`,
`strict_hosts`) applies as it does to the submission: the pre-flight verifies the normalized URL, and a canonical or
a redirect target is "one of your hosts" when the key provider has a key for it.

An invalid block is one `critical` log line and the pre-flight is off (`VerifyConfig::loadOrDisabled()`); `check`
prints the error (`verify.config`).
