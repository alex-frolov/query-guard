# Changelog

All notable changes to this project are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and the project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Fixed

- `n-plus-one` no longer skips a query because of any `IN (...)` containing a comma.
  Only a genuine list of keys (placeholders or numbers, two or more) counts as a batch
  fetch, so `WHERE status IN ('new','paid') AND user_id = ?` repeated in a loop is
  reported again — it is the most ordinary shape of query there is, and the false
  negative was in the flagship rule.
- `LIMIT` and table names are no longer matched inside string literals or comments.
- A configuration value that cannot be read now produces a warning in the summary instead
  of a silent default. `mode="strickt"` used to leave the suite in `report`, where
  nothing fails, and say nothing about it.
- `QUERY_GUARD_GENERATE_BASELINE=1` without a `baseline` parameter now says that nothing
  was written, instead of doing nothing quietly.
- `strict` mode no longer overwrites PHPUnit's own exit code when PHPUnit is already
  failing the run — its code is the more specific one.
- PostgreSQL table sizes are resolved through `to_regclass` rather than `WHERE relname =
  ?`, which picked an arbitrary table when the same name existed in several schemas.
- EXPLAIN parameters are bound by type rather than always as strings.
- A baseline signature no longer has the project path stripped out of its fingerprint.

### Changed

- The raw stack is no longer kept in a `QueryEvent`: the adapter resolves the callsite
  while recording and stores only that. 200 frames per query cost about 106 MB per 1000
  queries and a trace lives until its test ends; it is now about 0.5 MB. `QueryEvent`
  takes an optional `callsite:` argument, and passing `stack:` still works.
- `CallsiteResolver` memoises its verdict per file — resolution now runs on every
  recorded query.
- `Mode::fromString()` removed: reading a mode out of the configuration belongs to
  `ExtensionConfiguration`, which warns about a value it did not recognise.
- `composer.lock` is no longer committed; CI resolves dependencies on every run.

## [0.1.0] - 2026-08-27

The first public release. Everything below arrived at once, so the list is the
feature set rather than a history of changes.

### Added

- PHPUnit 10.5+ extension: one trace per test, opened after `setUp()` so fixture
  queries never reach the rules.
- Doctrine adapter — a dedicated DBAL middleware (Driver → Connection → Statement)
  with per-query timings, for DBAL 3 and 4.
- Eloquent adapter — subscribes to `QueryExecuted` through the event dispatcher,
  auto-discovered via `QueryGuardServiceProvider`.
- Lazy-loading recognition for Doctrine: findings name the association
  (`App\Entity\Order::$customer`), for ORM 2 proxies and ORM 3 native lazy objects.
- Tier 1 rules: `n-plus-one`, `duplicate-query`, `query-in-loop`, `no-limit`,
  `select-star`, `query-count`.
- Tier 2 rules over `EXPLAIN`: `no-possible-index`, `table-scan`, `filesort`,
  `temporary-table`, for MySQL/MariaDB and PostgreSQL.
- Baseline: `QUERY_GUARD_GENERATE_BASELINE=1`, keyed by `rule|file|fingerprint`.
- `#[AllowQueries]` and `#[IgnoreRule]` attributes.
- `report` and `strict` modes.

[Unreleased]: https://github.com/alex-frolov/query-guard/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/alex-frolov/query-guard/releases/tag/v0.1.0
