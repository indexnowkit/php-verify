# Contributing

This repository is a read-only split of [`indexnowkit/php`](https://github.com/indexnowkit/php) (`packages/verify`).
Please open issues and pull requests there; releases are tagged in the monorepo as `verify@x.y.z` and mirrored here.

Quick rules (details in the monorepo's CONTRIBUTING.md):

- Every change comes with tests; the decisions of the pre-flight (what passes, what is skipped, what is fetched) are
  covered by `VerifyingSubmitterTest`, the parser by `PageSignalsTest` (add a fixture for every new markup shape).
- phpstan level 9 and php-cs-fixer must pass.
- The package is a consumer of `indexnowkit/core`: nothing here may require a change in the core to work.
- The log lines are a contract operators grep (`docs/operations.md`): change them there first.
