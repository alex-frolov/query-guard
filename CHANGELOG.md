# Changelog

All notable changes to this project are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and the project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- `duplicate-query-threshold`, the threshold of `duplicate-query` under a name that matches
  its rule, as `n-plus-one-threshold` and `query-in-loop-threshold` already did.

### Deprecated

- `duplicate-threshold`. It is still read, and the summary asks for the rename; it stops
  being read in 2.0. When both names are set, `duplicate-query-threshold` wins and the old
  one is called out as ignored.

## [0.3.0] - 2026-09-13

### Added

- `format-version` in the baseline and in the JSON report. It goes up only on an
  incompatible change to the file. A baseline without it is read as format 1; a baseline in
  a format the running release cannot read silences nothing, and the summary says so.
- [UPGRADING.md](UPGRADING.md) lists what the public API is, and why the adapter, platform
  and rule interfaces are not part of it.
- [UPGRADING.md](UPGRADING.md) also names the two things a minor release may still change:
  finding signatures, when SQL normalisation improves (the dialect first), and severity,
  which can rise when a rule learns to prove what it used to guess (Eloquent's
  `n-plus-one` first). Both say what to do with a baseline and with `fail-on="error"`.

### Changed

- Every class outside the public API is marked `@internal`: everything except `Extension`,
  `QueryGuard`, `Attribute\AllowQueries`, `Attribute\IgnoreRule`,
  `Adapter\Doctrine\Middleware`, `Adapter\Eloquent\QueryGuardServiceProvider`,
  `Collector\QueryCollector`, `Query\QueryEvent` and `Query\Callsite`. Within those,
  `QueryGuard::activate()`, `deactivate()`, `isActive()` and `QueryEvent::fingerprint()`,
  `callsite()`, `shape()`, `isSelect()`, `annotation()` are internal too.

### Removed

- The optional `QueryEnricher` parameter of `Adapter\Doctrine\Middleware::__construct()`.
  Nothing passed it, and a replacement could only reproduce or disable the built-in
  enrichment.

[Unreleased]: https://github.com/alex-frolov/query-guard/compare/v0.3.0...HEAD
[0.3.0]: https://github.com/alex-frolov/query-guard/compare/v0.2.1...v0.3.0
