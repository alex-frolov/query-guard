# Changelog

All notable changes to this project are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and the project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- Tier 2 counts plans that read no table apart from failures: `tier 2: 154 plans parsed,
  62 had nothing to judge, 0 failed.`, with the reasons the platform gave. MySQL answers a
  unique-key lookup that finds no row, `No tables used` or `Impossible WHERE` with no table
  in the plan; PostgreSQL does the same for `SELECT version()` and `WHERE 1 = 0`. They used
  to be counted as failed — a quarter of all plans on one MySQL suite.
- A failed plan says why: the summary names the first failure of each kind (the exception
  class and the first line of its message, or what was wrong with the plan).
- A notice when DBAL 3 nests a test's flushes without savepoints (its default; Symfony's
  `doctrine.dbal.use_savepoints` turns them on). Such a flush starts nothing the driver
  sees, so `query-in-loop` cannot tell it from the flush before it and a `flush()` in a
  loop under a test transaction goes unreported. The summary now names the connection and
  the setting. DBAL 4 always uses savepoints and is unaffected.

### Changed

- `query-in-loop` counts one Doctrine `flush()` as one unit of work. The unit of work writes
  every pending change from that single call, and a flush of five entities used to be
  reported as five queries from one place. A `flush()` inside a loop is still reported, and
  its message says how many units of work the queries came from. Eloquent is unaffected.
- `no-possible-index` no longer reports a single-table read that nothing filters. A scan
  with no `WHERE` — `findAll()` on a lookup table — has nothing for an index to narrow; on
  one MySQL suite such scans were 825 of 927 findings of this rule. In a join a table
  without a condition is still judged: MySQL puts the join condition on the other table,
  and a full scan of the first one is what a missing index on the join column looks like.
- `no-possible-index` and `table-scan` no longer report a derived table
  (`JOIN (SELECT ...) x`, Doctrine's paginator `dctrn_result`): it never has an index.
  The tables inside the subquery are now walked and judged on their own, which they were
  not before — so a subquery can bring findings of its own.
- Tier 2 messages name the table behind an alias: `no suitable index on "settings_entry"
  (t0)` instead of `on "t0"`. Only the text changed; the signature still holds the alias.
- The fingerprint collapses multi-row `VALUES`: `VALUES (?, ?), (?, ?)` and `VALUES (?, ?)`
  are one shape, as `IN (?, ?)` and `IN (?)` already were. This changes the signatures of
  findings on such statements — see [UPGRADING.md](UPGRADING.md#unreleased).
- Savepoint statements (`SAVEPOINT`, `RELEASE SAVEPOINT`, `ROLLBACK TO SAVEPOINT`) are
  left out of the trace on Doctrine. They come from the test harness: inside a test
  transaction that is rolled back afterwards, DBAL nests every `flush()` as a savepoint,
  while in production the same flush opens a plain transaction whose begin and commit were
  never recorded. They put a pair of extra "queries" around every flush, and the number in
  the savepoint name made each nesting level a shape of its own — on one suite a
  `wrapInTransaction()` line was a `query-in-loop` finding made of nothing else. Query
  counts in the summary and in `max-queries` drop by those statements.
- Frames of Symfony's compiled container (`var/cache/<env>/Container<hash>/`) are stepped
  over in the call chain, as `vendor/symfony/` already was.

### Fixed

- A table entry without a name in MySQL's plan — an outer query settled before it reads
  anything, holding only a subquery — was reported as a table called `"unknown"`.
- README: a parallel run (`paratest`, `pest --parallel`, `artisan test --parallel`) prints
  no worker's summary at all — ParaTest starts every worker with `--no-output`. The README
  said each worker printed its own; the findings are only in `report-json`.

## [1.0.0] - 2026-09-13

The public API drawn in 0.3.0 is now stable: nothing in it breaks before 2.0. It is the
same API as 0.3.0 — Roave BC Check reports no break between the two — and it was checked
by installing 0.3.0 from Packagist into a Doctrine project and a Laravel one, wired by the
README alone: neither needed an `@internal` class, and both reported
what the source build had reported. [UPGRADING.md](UPGRADING.md#what-is-public) lists the
API, and the two things a minor release may still change.

### Added

- `duplicate-query-threshold`, the threshold of `duplicate-query` under a name that matches
  its rule, as `n-plus-one-threshold` and `query-in-loop-threshold` already did.

### Deprecated

- `duplicate-threshold`. It is still read, and the summary asks for the rename; it stops
  being read in 2.0. When both names are set, `duplicate-query-threshold` wins and the old
  one is called out as ignored.

### Changed

- A break of the public API fails the build: Roave BC Check in CI is blocking rather than
  informational. An intended break has to be recorded in
  `.roave-backward-compatibility-check.xml`, which after 1.0 means a major release.
- Security fixes go to the latest `1.y` only; 0.x is no longer supported.

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

[Unreleased]: https://github.com/alex-frolov/query-guard/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/alex-frolov/query-guard/compare/v0.3.0...v1.0.0
[0.3.0]: https://github.com/alex-frolov/query-guard/compare/v0.2.1...v0.3.0
