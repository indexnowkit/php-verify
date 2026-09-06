# Backward compatibility

`indexnowkit/verify` follows SemVer and the tiers of the core's [docs/bc.md](https://github.com/indexnowkit/php/blob/main/packages/core/docs/bc.md).
**Before 1.0, minor versions may contain breaking changes**, listed under "Changed" in [CHANGELOG.md](../CHANGELOG.md).

| Tier | Members |
|---|---|
| **Call** — signatures only grow by appended, defaulted parameters; pass anything past the first argument by name | `VerifyingSubmitter` (constructor, `submit()`, `prepare()`, `addListener()`), `VerifyConfig` (constructor, `fromArray()`, `disabled()`, `loadOrDisabled()`, `toArray()`, `transportConfig()`, `userAgent()`, `defaultUserAgent()`), `PageSignals::fromResponse()`, `UrlReference::resolve()`, `RobotsCache` (constructor, `disallows()`, `key()`), `VerifyingSubmitterFactory` (constructor, `create()`, `inner()`), `Check\SampleCheck` |
| **Value objects** — `final readonly`, properties only appended with defaults | `PageSignals`, `VerifyConfig` |
| **Constants** — referenced, not hard-coded; values may change in a minor | `VerifyConfig::OPTIONS`, `DEFAULT_*`, `MAX_DELAY`, `PageSignals::MAX_BYTES`, `SOURCE_*`, `SampleCheck::CODE` |
| **Enum** — closed sets | `RedirectPolicy`, `NonCanonicalPolicy`, `OriginErrorPolicy` |
| **Texts that are a contract** | the log-line prefixes of [operations.md](operations.md) (operators grep them); the `Reason` cases of the skipped results (`Noindex`, `RobotsDisallowed`, `NonCanonical`, `Redirected`, `OriginError`) and the check codes `verify.installed`, `verify.dispatch`, `verify.sample` |

Not covered: `Verdict` (`@internal`), the `error` sentences of the results and the exception messages, anything under
`tests/`.

The package pins `indexnowkit/core ^0.11`: the `Reason` cases it uses and `Retry\ForbiddenCounter`'s sibling
`Http\TransportFactory::lazy(..., $extraHeaders)` appeared there.
