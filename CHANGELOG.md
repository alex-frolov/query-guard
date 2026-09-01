# Changelog

All notable changes to this project are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and the project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- `fail-on` parameter: the lowest severity `strict` mode will fail a run over — `error`,
  `warning` (default) or `info`. Severity was already presented as a scale of certainty
  in the summary, but the gate ignored it and failed on everything, so enabling
  `select-star` — an `info` rule that fires on every Eloquent query there is — turned a
  whole suite red. Everything found is still printed; only the exit code changes.

### Fixed

- Tier 2 resolves an `EXPLAIN` connection **per connection** instead of holding a single
  one. A static field used to keep whichever database connected most recently, so on a
  project with two of them the plan of one was read against the other — and parsed with
  the other's platform driver on top. Worse, that resolution happened once and was frozen
  for the whole run: a secondary SQLite connection opening before the main one switched
  plan rules off entirely, and the summary blamed the unsupported platform rather than the
  choice of connection. Because connections open lazily, which one won depended on test
  order, so `--filter` could turn tier 2 on and off. Each connection is now explained by
  itself, an unsupported one is named while the others keep working, and the
  "this platform cannot judge" caveats come from the platforms that actually answered.
- Comments and string literals are stripped in a single pass, so a `--` or `/*` **inside
  a string literal** no longer swallows the rest of the statement. It made
  `isBatchFetch()` answer "no" for a real `IN (?, ?)` list — a false negative there is a
  false positive in the flagship rule — and hid a `LIMIT` from `no-limit`.
- `isSelect()` now looks past `--` line comments as well as block comments. A statement
  introduced by one — sqlcommenter-style tracing emits them — was read as a write and
  silently dropped out of `n-plus-one`, `duplicate-query` and tier 2.
- `shape()` no longer lets a parameter it cannot serialise escape as an exception. It
  runs inside `Test\Finished`, so the throw reached PHPUnit's event dispatcher: a tool
  that watches for problems must not be able to break the suite it is watching.
- Per-resolver callsite memoisation is keyed by the resolver object rather than by
  `spl_object_id()`, which PHP reuses after an object is freed — a resolver created after
  another was collected could inherit its answer and report a callsite it was told to skip.
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
- `Sql` memoises the stripped form of a query. The helpers are called once per rule per
  query, and `touchesTable()` once per configured `large-tables` entry on top of that.
- `Mode::fromString()` removed: reading a mode out of the configuration belongs to
  `ExtensionConfiguration`, which warns about a value it did not recognise.
- **BC break.** `OrmAdapter::explainer(): ?Explainer` is now
  `OrmAdapter::explainers(): array<string, Explainer>`, keyed by connection name;
  `AdapterSet` follows. A custom adapter has to return a map instead of one explainer.
- **BC break.** `PlanProvider` is constructed from an `AdapterSet` rather than a fixed
  explainer and driver, and resolves both per connection. `rowsFor()` takes the `Plan`
  alongside the node, and `driver()` is now `driverFor(Plan)` — the driver depends on
  which connection produced the plan. `Plan` carries the connection it came from.
- `RuleEngine`'s deferred rule set is gone, along with its second constructor argument.
  It existed only because tier 2 needed a live connection before its rules could be
  built; nothing defers any more.
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
