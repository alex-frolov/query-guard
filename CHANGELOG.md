# Changelog

All notable changes to this project are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and the project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Fixed

- **A per-request SQL comment no longer hides every finding.** `Fingerprint` normalised
  its own way and did not strip comments, while `Rule\Sql` did — so on a project whose
  tracing middleware appends `/* trace=... */` to every statement, the same query arrived
  with a different fingerprint each run. `n-plus-one` and `duplicate-query` group by
  fingerprint: both saw a suite of unique queries and reported nothing at all, silently.
  Both now go through one `Query\SqlText`, which is the only place that knows where a
  comment ends and a literal begins. **This changes fingerprint values** — a baseline
  keyed on a commented statement has to be regenerated; see `UPGRADING.md`.
- **The Eloquent listener no longer settles for one connection without saying so.**
  `DB::getFacadeRoot()` hands back a `DatabaseManager`, which has no `listen()` of its
  own: the call went through `__call` and landed on the default connection, covering it
  and nothing else — while `isInstalled()` reported success and the summary looked
  healthy. The adapter now asks the container for `events` first, then the manager for
  the dispatcher its connections share, and only then falls back to a connection-level
  subscription — which now announces, in the summary, how much of the project it can see.
- **Two databases sharing a name are no longer one database.** Connections were keyed by
  `dbname`, so a primary and its read replica collided and the second overwrote the
  first: tier 2 then explained the replica's queries against the primary. They are now
  keyed by the whole endpoint — database, host, port, user — and labelled by the database
  name, with a second endpoint claiming a taken name reported as `name#2` and the rename
  announced. Reconnecting the same endpoint keeps its name.
- Positional parameters reaching `DoctrineExplainer` zero-indexed are rebased to one.
  DBAL binds them correctly itself, but the deprecated `Statement::execute($params)` path
  passes the caller's list unchanged, and `bindValue(0, ...)` is rejected outright — the
  EXPLAIN then failed for a reason with nothing to do with the query under study.

### Changed

Both of these break the released API. Before 1.0 that is allowed; `UPGRADING.md` carries
the instructions.

- `OrmAdapter` gained `notices()`, so a custom adapter has to implement it — returning an
  empty list is the right answer unless it has something to say about a run that
  collected queries.
- `DoctrineAdapter::markConnected()` became `register()`, which takes an endpoint
  alongside the label and returns the name the connection is actually known under. Only
  `Adapter\Doctrine\Driver` calls it.

### Removed

- Public API nothing called, taken out before 1.0 makes it permanent: `Plan::estimatedRows()`
  and the `Plan::$cost` constructor argument (plan cost is no longer parsed),
  `Finding::withSignature()`, `RuleEngine::ruleIds()`, `Callsite::equals()`,
  `Trace::durationMs()`, `Trace::isEmpty()` and `Platform\Json::float()`. Replacements,
  where there are any, are in `UPGRADING.md`.

### Added

- `report-json` parameter: the whole run written to a file as JSON — counters, notices
  and findings, with paths relative to the working directory and a `failing` flag
  answering the only question a CI script usually has. The console summary is written to
  be read by a person and will be reworded; the README's own recipes were parsing it
  (`grep 'against a budget'`), which is a fair description of the gap rather than a
  defence of it. The summary is never replaced by the file: a run that writes a report
  and prints nothing is a run whose findings nobody sees.
- `skip-paths` parameter: extra path fragments a callsite is never blamed on, added to
  the built-in list rather than replacing it. A project vendoring its own framework had
  no way to say so, and findings pointed at files nobody was going to edit. Held
  statically for the same reason `QueryGuard` is — a DBAL middleware is built by the
  application's container, which cannot be handed a configured resolver.
- Baseline entries that silenced nothing are counted in the summary. A baseline only ever
  grew otherwise: the finding gets fixed, the entry stays, and from then on the file
  quietly silences something that no longer exists — including, in time, a regression
  landing on the same rule in the same file. Deliberately not called "obsolete": after
  `--filter` or an excluded group, an unmatched entry only means its test did not run,
  and a tool that called that obsolete would teach people to delete live entries.
- `OrmAdapter::notices()`: what an adapter has to say about a run that *did* collect
  queries. `installationHint()` only covered a run that collected nothing, and the case
  that needed saying most was the other one — a summary about to look healthy while whole
  connections went unwatched.
- `SECURITY.md`, `UPGRADING.md`, issue templates and a pull-request template.
- `fail-on` parameter: the lowest severity `strict` mode will fail a run over — `error`,
  `warning` (default) or `info`. Severity was already presented as a scale of certainty
  in the summary, but the gate ignored it and failed on everything, so enabling
  `select-star` — an `info` rule that fires on every Eloquent query there is — turned a
  whole suite red. Everything found is still printed; only the exit code changes.
- The test suite is statically analysed too, at level 9 — `tools/phpstan/tests.neon`, run
  by CI alongside the `src` analysis. The fixtures are where `FakeAdapter` and the
  hand-fed collector live: they are written against the seams the package publishes, and
  when a seam moves without them the suite still passes, because it is exercising the
  fixture rather than the package. Turning it on found seven real defects in the tests,
  among them `reset()` and `end()` results used without accounting for `false`.
- A `coverage` CI job (pcov), reporting a figure on every run and keeping the clover
  report as an artifact. No minimum is enforced: the end-to-end tests launch PHPUnit in a
  child process, which contributes nothing to the parent's coverage, so the figure always
  understates what the suite actually exercises.
- Unit tests for the wiring — `Extension`, all six subscribers and the Eloquent service
  provider — which had measured at exactly 0% (0 of 145 statements). Not a rounding
  error: every test that touched them ran in a child process. They are now driven
  directly, with PHPUnit's own event objects built by hand, and read at 96%; the six
  statements left are the ones a test cannot reach without taking the suite down with it
  (`strict` mode's `exit(1)` at shutdown, and the two bail-outs that need a `TextUI`
  configuration nobody can construct). This adds to the end-to-end suite rather than
  replacing any part of it: a unit test can check what a subscriber decides, only a real
  runner can check that PHPUnit calls it, and when.
- `requireCoverageMetadata="true"`: a test without `#[CoversClass]` or `#[CoversNothing]`
  is an error while coverage is being collected. The suite already followed this; now it
  cannot quietly stop.
- `composer coverage`, `composer analyse-tests` and `composer style` scripts.
- A backward-compatibility job: Roave BC Check against the most recent tag, installed
  with `composer global require` so the tool's dependency tree stays out of
  `composer.json`. Informational while the package is 0.x — a break is meant to be
  visible in the pull request that causes it and to land in this file, not to be blocked.
  It becomes blocking at 1.0.
- Dependabot for GitHub Actions and Composer. The two are watched for different reasons:
  actions are pinned by major, so a bad release inside that major reaches CI on its own,
  while Composer — a library with no lock file — only raises a pull request when a release
  falls outside a declared constraint, which is always the deliberate question "should
  this package support a new major?". Doctrine and Illuminate majors are excluded: the
  supported ORM range is a promise, widened by hand with the adapter changes that make it
  true.

### Fixed

- `composer check` ran php-cs-fixer as `@fix -- --dry-run --diff`, and Composer passes the
  `--` through: the fixer read `--dry-run` as a path and exited 16, so the aggregate
  command had never worked. CI invokes the binaries directly, which is why it went
  unnoticed. Split into a `style` script that `check` calls.
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
- `no-limit` sees schema-qualified table names. `large-tables="orders"` now matches
  `FROM public.orders`, `FROM "public"."orders"` and ``FROM `shop`.`orders` `` — Doctrine
  qualifies by schema whenever one is configured, and the rule used to go quiet on
  exactly those projects while looking as if it were working. A name in the
  configuration may be qualified too (`public.orders`), and then the schema is matched as
  written. The reverse mistake is gone with it: `FROM shop.orders` no longer counts as a
  hit for a table named `shop`.
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
- **BC break.** `Mode::fromString()` removed: reading a mode out of the configuration
  belongs to `ExtensionConfiguration`, which warns about a value it did not recognise.
- **BC break.** `OrmAdapter::explainer(): ?Explainer` is now
  `OrmAdapter::explainers(): array<string, Explainer>`, keyed by connection name;
  `AdapterSet` follows. A custom adapter has to return a map instead of one explainer.
- **BC break.** `PlanProvider` is constructed from an `AdapterSet` rather than a fixed
  explainer and driver, and resolves both per connection. `rowsFor()` takes the `Plan`
  alongside the node, and `driver()` is now `driverFor(Plan)` — the driver depends on
  which connection produced the plan. `Plan` carries the connection it came from.
- **BC break.** `RuleEngine`'s deferred rule set is gone, along with its second
  constructor argument. It existed only because tier 2 needed a live connection before its
  rules could be built; nothing defers any more.
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
