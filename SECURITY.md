# Security

Report vulnerabilities privately to the maintainer (see `composer.json` → `authors`) or through GitHub's private
vulnerability reporting on [indexnowkit/php](https://github.com/indexnowkit/php/security). Please do not open a
public issue for an unfixed vulnerability. Reports are acknowledged within a few days; fixes ship as patch releases
of the affected minor.

## What the pre-flight does with untrusted answers

The page under check is a document from the network, and it names other URLs (`Location`, `rel="canonical"`). The
decorator:

- fetches only URLs it was asked to submit, plus `/robots.txt` of their hosts, plus redirect targets under
  `verify.redirect: follow` — and those only over http(s), on hosts the key provider has a key for
  (`hosts` / `base_url`), at most `verify.max_redirects` hops, never in a loop. A `Location` or a canonical on any
  other host is a skip, never a request and never a submission: a page cannot make the application announce another
  site, nor fetch from one;
- reads at most the first 256 KiB of a body for the signals and only its `<head>`; the transport caps the body it
  downloads; nothing of the body reaches a log line — only the URL, the status and the parsed values;
- does not follow redirects in the transport (the transport is built without them) and does not send the IndexNow key
  anywhere: the pre-flight GETs carry `verify.user_agent` and nothing else;
- treats a `robots.txt` it cannot fetch as absent, with a warning; a `robots.txt` cannot make it fetch anything.

The `check --sample` lines print the URL, the status and the parsed signals; sample URLs come from the operator.

Reports are acknowledged within 5 business days; a fix or a mitigation plan follows within 30 days.
