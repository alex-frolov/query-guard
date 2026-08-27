# Changelog

All notable changes to this project are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and the project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

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
