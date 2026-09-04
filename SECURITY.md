# Security policy

## Supported versions

While the package is `0.x`, only the most recent release receives fixes. There are no
maintained branches behind it.

| Version | Supported |
|---|---|
| 0.1.x | ✅ |
| < 0.1 | — |

## Reporting a vulnerability

Report privately through
[GitHub's security advisories](https://github.com/alex-frolov/query-guard/security/advisories/new),
not as a public issue. If that is unavailable to you, write to `aleksander@frolov.guru`.

Expect an acknowledgement within a week and an assessment within two. A fix ships as a
patch release, and the advisory is published with it.

## What is in scope

query-guard is a development dependency: it runs inside a PHPUnit process, on a developer
machine or a CI runner, against a test database. Its exposure follows from that, and the
following are in scope:

- **SQL built from a query under study.** Tier 2 prefixes a statement with `EXPLAIN` and
  runs it on the connection that issued it. A statement is never composed from user input
  by this package, but a path that lets one be is a vulnerability.
- **Reading and writing project files.** The baseline and the JSON report are written to
  paths taken from `phpunit.xml`; a path escaping the configured one is a vulnerability.
- **Anything written into a report.** Bound values reach `Fingerprint` and `shape()`,
  which means a credential passed as a query parameter must not end up printed in the
  summary or written to the JSON report. Findings quote the *normalised* SQL, with
  literals replaced — a path that leaks the values instead is a vulnerability.
- **Leaving the extension active outside a test run.** The adapters live in the
  application's configuration and are reachable in production; they must stay inert
  there. A path that starts collecting outside PHPUnit is a vulnerability.

## What is not in scope

- Findings that are wrong, missed or noisy. Those are bugs — open an issue.
- The `EXPLAIN` a project's own database refuses or answers oddly.
- Anything requiring an attacker to already control `phpunit.xml`, the test suite, or the
  `composer.json` of the project under test. At that point they control the test process
  outright, and this package is not the weakest thing in it.
