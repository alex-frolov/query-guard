# query-guard

[![CI](https://github.com/alex-frolov/query-guard/actions/workflows/ci.yml/badge.svg)](https://github.com/alex-frolov/query-guard/actions/workflows/ci.yml)
[![Packagist](https://img.shields.io/packagist/v/alex-frolov/query-guard.svg)](https://packagist.org/packages/alex-frolov/query-guard)
[![PHP](https://img.shields.io/packagist/php-v/alex-frolov/query-guard.svg)](composer.json)
[![License](https://img.shields.io/packagist/l/alex-frolov/query-guard.svg)](LICENSE)

*[Русская версия](README.ru.md)*

A PHPUnit extension that watches the queries your tests already make and reports the
performance problems hiding behind them — N+1 above all.

Install it, add six lines to `phpunit.xml`, and run your suite as usual. No assertions
in your tests, no separate command, no code changes.

> **Status: 0.1.0, the first public release.** Everything documented below works and is
> covered by tests (154 of them, on PHPUnit 10.5–13, Doctrine ORM 2–3, DBAL 3–4, MySQL
> and PostgreSQL). The API may still shift before 1.0.

## What it looks like

```
query-guard
  tests traced: 404, queries: 25736 (in setUp: 0)

  findings: 207

  * [error] n-plus-one — App\Tests\Controller\TimesheetControllerTest::testExportAction
    App\Entity\Timesheet::$tags — lazy-loaded association, 10 queries
    src/Entity/Timesheet.php:418

  * [warning] n-plus-one — App\Tests\Controller\TimesheetControllerTest::testSaveRates
    50 queries of the same shape from one place, different values: SELECT r0_.id AS id_0, ...
    src/Repository/TimesheetRepository.php:810
```

Those two are real: the numbers above come from running query-guard over
[Kimai](https://github.com/kimai/kimai)'s controller suite. 207 findings in 21 distinct
places, including three rate lookups per timesheet on every flush.

## Why not a static analyser

**N+1 is not a property of a query. It is a property of a sequence of queries.**

This is not a philosophical point — it is measurable. On MySQL, where plan analysis works
completely, the plan of every single query in a textbook N+1 is *flawless*: `access_type:
const`, `key: PRIMARY`, one row examined, zero issues. The problem is that there are fifty
of them. No amount of `EXPLAIN` will tell you that.

Nor will an AST-based analyser: lazy loading happens when you touch a property, dynamic
query builders resolve at runtime, and neither is visible in source code.

| Tool | Zone | Overlap with query-guard |
|---|---|---|
| [phpstan-dba](https://github.com/staabm/phpstan-dba) | **Static SQL**: result types, syntax, placeholders, statically resolvable query strings | None. It reads code, we watch a live run. Use both |
| [phpstan-doctrine](https://github.com/phpstan/phpstan-doctrine) | DQL correctness without a database | None |
| [phpunit-query-count-assertions](https://github.com/mattiasgeniar/phpunit-query-count-assertions) | Query counters, duplicates, EXPLAIN — via a trait and manual assertions | Closest neighbour. See below |
| **query-guard** | **Runtime trace**: what actually ran, in what order, from where | — |

### About the closest neighbour

Tested on real projects rather than read from its README:

- its duplicate detection compares SQL **and bound values**, so a real N+1 — where the
  values differ by definition — produces **zero** duplicates and a green test;
- lazy-loading detection requires Laravel; on Doctrine `assertNoLazyLoading()` returns
  green without checking anything;
- query timing is unavailable on Doctrine (it reuses Doctrine's logging middleware, which
  records queries *before* execution), so `assertMaxQueryTime(0.001)` passes on six real
  queries;
- on PostgreSQL, plan analysis silently switches itself off and reports green with zero
  queries analysed.

query-guard writes its own DBAL middleware (so timings exist), fingerprints SQL with the
values stripped (so N+1 is visible), and says out loud when a rule cannot judge instead of
showing green.

## Install

```bash
composer require --dev alex-frolov/query-guard
```

```xml
<!-- phpunit.xml -->
<extensions>
    <bootstrap class="QueryGuard\Extension">
        <parameter name="mode" value="report"/>
    </bootstrap>
</extensions>
```

Then wire the adapter for your ORM — one line for Doctrine, nothing at all for Eloquent.

### Doctrine

The middleware has to be in the connection configuration *before* the connection is
created, which is why the extension cannot install it for you:

```yaml
# config/services_test.yaml
services:
  QueryGuard\Adapter\Doctrine\Middleware:
    tags: ['doctrine.middleware']
```

Works with Doctrine ORM 2 and 3, DBAL 3 and 4.

### Eloquent

Nothing to do: Laravel discovers `QueryGuardServiceProvider` automatically. It subscribes
to the event dispatcher, so every connection is covered — including ones created later,
and including queries made in `setUp()`.

### Anything else

The collector is the seam. Feed it from a PDO decorator, another ORM, wherever:

```php
use QueryGuard\Query\QueryEvent;
use QueryGuard\QueryGuard;

QueryGuard::collector()->record(new QueryEvent(
    sql: 'SELECT * FROM users WHERE id = ?',
    params: [42],
    durationMs: 0.4,
    stack: debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS),
));
```

Outside a PHPUnit run the collector is a null object: this code does nothing and costs
nothing.

If you already know where the query came from, pass `callsite:` instead of `stack:` and
skip the stack walk entirely — that is what both built-in adapters do.

## Rules

### Tier 1 — works on day one, no reference database needed

| Rule | What it means | Default |
|---|---|---|
| `n-plus-one` | One query shape, one place in the code, differing values | on, threshold 3 |
| `duplicate-query` | Same query, same values, repeated | on, threshold 5 |
| `query-in-loop` | Many queries of *different* shapes from one place | on, threshold 5 |
| `no-limit` | `SELECT` without `LIMIT` against a table you flagged as large | off until `large-tables` is set |
| `select-star` | `SELECT *` | off |
| `query-count` | Query budget per test | off until `max-queries` is set |

Three rules stay silent until configured, on purpose. `select-star` would fire on every
Eloquent query (it is Eloquent's default); "a large table" cannot be determined from a
three-row fixture database; and a query budget is a project policy, not a constant.

`n-plus-one` only looks at reads, requires the repeated values to differ, and ignores
batch fetches (`IN (?, ?, ?)`) — that pattern is the *cure* for N+1, not the disease.

### Tier 2 — plan rules, needs a database with real data

Enable with `tier2="true"`. Runs `EXPLAIN` once per distinct query shape, on the same
connection and inside the same transaction.

**Several connections are handled separately.** Each is explained by itself, with its own
platform driver — a query that ran on the analytics database is never explained against
the primary one. A connection on a platform tier 2 does not support is named in the
summary and skipped; the others keep working.

| Rule | MySQL / MariaDB | PostgreSQL |
|---|---|---|
| `no-possible-index` | ✅ | — the platform does not report candidate indexes |
| `table-scan` | ✅ | ✅ |
| `filesort` | ✅ | ✅ |
| `temporary-table` | ✅ | — no equivalent worth flagging |

Where a rule cannot work, the summary **says so**. A green report and "we did not look"
must never be the same output.

**Tier 2 needs volume.** Plan rules stay quiet until a table has at least `min-rows`
(default 1000) rows, because on a small table the optimiser's own estimate lies — we have
watched a competitor report `error: Full table scan` on a five-row table. The one
exception is `no-possible-index`: a missing index is a fact about the schema, true even
when the table is empty.

## When query-guard stays silent

A tool that shows green when it did not look is worse than no tool. Everything on this
list is printed as a notice in the summary — except the last three, which are silent by
design:

| Situation | What you see |
|---|---|
| No ORM found in the project | `neither Doctrine nor Eloquent was found` |
| ORM found, interception never took | the adapter is named, with the specific fix |
| Interception worked, zero queries all suite | `not "all clear", it is "nothing to look at"` |
| Queries outside a test (bootstrap, data providers) | counted, and the count is reported |
| A configuration value it could not read | the parameter, the value, and what was used instead |
| `tier2` on, no connection ever appeared | `no plans were looked at` |
| `tier2` on an unsupported platform | the platform is named; supported ones listed |
| `no-possible-index` on PostgreSQL | the rule says it cannot judge — not that all is well |
| `temporary-table` on PostgreSQL | same |
| **`--no-output`** | nothing: the extension does not load at all |
| **A table below `min-rows`** | nothing: plan rules deliberately do not judge small tables |
| **`#[IgnoreRule]` / a baseline entry** | the baseline count is shown; a per-test ignore is not |

Two more worth knowing:

- **Tier 2 does not work on Eloquent.** `QueryExecuted` gives no access to a connection
  on which `EXPLAIN` could run inside the same transaction, and there is nothing to
  quietly substitute. Tier 1 works fully.
- **Queries made in `setUp()` are not analysed.** They are counted and shown separately
  (`in setUp: N`). That is the decision the whole false-positive story rests on — a
  factory creating 50 rows in a loop is 50 identical INSERTs from one callsite.

## What it costs

Worth knowing before putting it in CI, and measured rather than estimated:

| | Cost |
|---|---|
| Outside a PHPUnit run | nothing. The collector is a null object and no stack is captured |
| Between tests, in `setUp()` | a query is recorded; no rule runs |
| Per traced query | one `debug_backtrace` (200 frames) and one callsite resolution — **~0.006 ms, i.e. ~0.15 s per 25 000 queries** |
| Memory per traced query | SQL, bound values and one callsite — **~0.5 MB per 1000 queries**, freed when the test ends |
| Tier 2 | one `EXPLAIN` per *distinct query shape* per run, not per query; on PostgreSQL one extra size lookup per table |
| End of run | rules run over each trace as its test finishes; the summary is printed once |

The stack is captured only while a test is being traced, and only the resolved callsite
is kept — 200 raw frames per query cost about 106 MB per 1000 queries and used to be the
one way this tool could hurt a suite.

Tier 2 is the part with a real price: it talks to the database. Its own queries are
excluded from the trace, so the tool never counts its own traffic.

## Baseline

Point query-guard at an existing project and you will get hundreds of findings. That is
what a baseline is for:

```bash
QUERY_GUARD_GENERATE_BASELINE=1 vendor/bin/phpunit
```

```xml
<parameter name="baseline" value="query-guard-baseline.json"/>
```

Everything known stays quiet; anything new shows up. Commit the file.

A finding is keyed by `rule|file|fingerprint` — deliberately **without** the line number
and **without** the test name, since both move for harmless reasons and would reset your
baseline for nothing. Paths in the file are relative, so it survives the trip to CI.

The summary always reports how many findings the baseline silenced.

## Per-test overrides

```php
use QueryGuard\Attribute\AllowQueries;
use QueryGuard\Attribute\IgnoreRule;

#[AllowQueries(50)]
#[IgnoreRule('n-plus-one')]
public function testImportsLargeFile(): void
```

Both work on the class as well as the method.

## Configuration

| Parameter | Meaning | Default |
|---|---|---|
| `mode` | `report` — print a summary; `strict` — fail the run | `report` |
| `fail-on` | Lowest severity `strict` fails on: `error`, `warning`, `info` | `warning` |
| `baseline` | Path to the baseline file | not set |
| `n-plus-one-threshold` | Repeats before it counts as N+1 | `3` |
| `duplicate-threshold` | Repeats before it counts as a duplicate | `5` |
| `query-in-loop-threshold` | Queries from one place before it looks like a loop | `5` |
| `max-queries` | Query budget per test | not set, rule silent |
| `large-tables` | Comma-separated tables for `no-limit` | not set, rule silent |
| `select-star` | Enable `select-star` | `false` |
| `tier2` | Enable plan rules | `false` |
| `min-rows` | Table size below which plan rules do not judge | `1000` |

**`fail-on` reads the severity scale.** `[error]` means the adapter recognised lazy
loading and named the association; `[warning]` that only the shape heuristic fired;
`[info]` is a style note. Everything found is always printed — `fail-on` only decides
what `strict` is willing to fail the run over. The default of `warning` deliberately
leaves `info` out: the only `info` rule is `select-star`, and `select *` is Eloquent's
default mode, so failing on it would turn a whole Laravel suite red the moment the rule
is enabled. Set `fail-on="error"` to fail only on what was proved rather than guessed.

A value that cannot be read — `mode="strickt"`, `max-queries="lots"` — produces a warning
in the summary naming the parameter, the value and what was used instead. It never falls
back in silence. A misspelled parameter *name* is the one thing that cannot be caught:
PHPUnit's `ParameterCollection` offers no way to enumerate what was actually written.

`strict` mode fails the whole run (exit code 1), not an individual test: PHPUnit's event
system gives an extension no way to mark a test as failed. When PHPUnit is already
failing the run for its own reasons, its exit code is left alone — it is the more
specific one.

## Recipes

### Catching an N+1 in five minutes

No baseline, no strict mode, no tier 2 — just point it at one test and read the output.

**1. Install the extension and wire the adapter** (Install section above). Defaults are
fine: `n-plus-one`, `duplicate-query` and `query-in-loop` are on out of the box, the
noisy rules are off.

**2. Run the one test you suspect:**

```bash
vendor/bin/phpunit --filter testExportAction
```

**3. Read the summary.** A finding names the rule, the test, the diagnosis and the line:

```
  * [error] n-plus-one — App\Tests\Controller\TimesheetControllerTest::testExportAction
    App\Entity\Timesheet::$tags — lazy-loaded association, 10 queries
    src/Entity/Timesheet.php:418
```

`[error]` means the adapter recognised lazy loading and there is nothing left to guess.
`[warning]` means only the shape heuristic fired — same query, same place, different
values — and it is worth a look but not a certainty.

**Nothing found?** Check the top of the summary before concluding there is no problem:
`tests traced: 1, queries: 0` means interception never took, and the notice below it says
what to fix. See [When query-guard stays silent](#when-query-guard-stays-silent).

When you are ready to hold the whole suite to this, read on.

### Adopting on a legacy project

You cannot fix hundreds of old tests at once, and you do not need to. The goal is to draw
a line: everything existing goes into the baseline, everything new is checked. The steps
below go from install to `strict`.

**1. Install and enable every check.** Extension and adapter as in the Install section
above. Keep `mode="report"` while you are still measuring the damage, and set the
baseline path right away — step 5 writes to it:

```xml
<!-- phpunit.xml -->
<extensions>
    <bootstrap class="QueryGuard\Extension">
        <parameter name="mode" value="report"/>
        <parameter name="baseline" value="tests/query-guard-baseline.json"/>
        <parameter name="n-plus-one-threshold" value="3"/>
        <parameter name="duplicate-threshold" value="5"/>
        <parameter name="query-in-loop-threshold" value="5"/>
        <parameter name="max-queries" value="50"/>
        <parameter name="select-star" value="true"/>
        <parameter name="large-tables" value="users,orders"/>
    </bootstrap>
</extensions>
```

`max-queries` here is a probe, not the budget you intend to keep — see steps 3 and 4.

**2. Run the suite and save the log.** Findings and query counts go to stdout:

```bash
vendor/bin/phpunit 2>&1 | tee query-guard.log
```

**3. Pick the real `max-queries` from the log.** Only breaches are printed, as
`N queries against a budget of M` — so one run shows you the tests above the probe and
nothing about the rest. To see the shape of the distribution, run it twice: once with a
low probe (say 20) and once with a high one (say 100). Between the two you have every
test's exact count.

```bash
grep 'against a budget' query-guard.log
```

Recommendations:

- set the global budget near the 90th–95th percentile of ordinary business tests — a
  number chosen "with headroom" protects nothing;
- genuinely heavy tests (bulk import, export, reporting) get their own
  `#[AllowQueries(N)]` instead of an inflated global budget;
- legacy code you cannot touch: leave its breaches to be absorbed by the baseline
  (step 4) — `query-count` findings are keyed per test, so new tests are still checked
  against the budget.

**4. Write the budget you picked back into `phpunit.xml`** — the probe from step 1 is
still in there, and step 5 is about to freeze whatever it produces:

```xml
<parameter name="max-queries" value="35"/>
```

Do this before generating the baseline, not after. A baseline generated against the probe
records `query-count` findings at the wrong threshold, and lowering the budget later then
looks like a wave of regressions.

**5. Generate the baseline and commit it:**

```bash
QUERY_GUARD_GENERATE_BASELINE=1 vendor/bin/phpunit
```

Everything currently found lands in `tests/query-guard-baseline.json`; the file goes into
the repository.

> **Set that variable for the one command, never in CI.** With it on, the run always
> passes and always rewrites the baseline — the quietest possible way to switch the tool
> off for good. If the variable is set but no `baseline` parameter is configured, the
> summary says so and nothing is written.

**6. Decide on tier 2 — only if the tests run on a database with real volume.** Plan
rules need rows and statistics; on a three-row fixture database the optimiser's estimate
lies and the rules do more harm than good (see the Tier 2 section above). If your test
database is a production copy or is seeded at scale, add:

```xml
<parameter name="tier2" value="true"/>
```

and regenerate the baseline (step 5), so existing plan findings are recorded as well.

**7. Re-run and verify:**

```bash
vendor/bin/phpunit 2>&1 | tee query-guard-after.log
```

The summary must show `silenced by baseline: N` and stay otherwise empty — anything that
still appears is either a flaky callsite or a regression between the two runs, and the
baseline has nothing to do with it.

**8. Switch the mode to `strict`:**

```xml
<parameter name="mode" value="strict"/>
```

From now on every finding outside the baseline fails the run: a new N+1 in a new test, a
moved callsite of an old one, a budget breach in a test whose name did not exist when the
baseline was written.

**One habit worth adopting together with the tool:** prepare test data in `setUp()`, not
in the test body. The trace opens after `setUp()` finishes, so factory INSERTs there are
kept out of the analysis; the same creation loop inside a test body is a textbook
`n-plus-one`/`duplicate-query` false positive — on your own fixtures.

### Starting a new project

No legacy, no baseline: every finding is either a bug you fix or an exception you
justify in writing. Enable everything that works on day one and keep `report` while
triaging:

```xml
<!-- phpunit.xml -->
<extensions>
    <bootstrap class="QueryGuard\Extension">
        <parameter name="mode" value="report"/>
        <parameter name="n-plus-one-threshold" value="3"/>
        <parameter name="duplicate-threshold" value="5"/>
        <parameter name="query-in-loop-threshold" value="5"/>
        <parameter name="max-queries" value="30"/>
        <parameter name="select-star" value="true"/>
    </bootstrap>
</extensions>
```

Two more parameters are worth adding, but only once each condition holds — neither has a
sensible value in the abstract:

```xml
<!-- your own large tables, by name; the rule is silent without this list -->
<parameter name="large-tables" value="users,orders"/>

<!-- only when the test database carries real volume: on a fixture-sized one the
     optimiser's estimate lies and the plan rules do more harm than good -->
<parameter name="tier2" value="true"/>
```

**1. Run and save the log:**

```bash
vendor/bin/phpunit 2>&1 | tee query-guard.log
```

**2. Triage every finding.** Three possible verdicts:

- *True positive* — fix the code, not the config: eager fetch (`JOIN`/`IN`) instead of
  lazy loading, one batched query instead of one per row, the missing index behind a
  `table-scan` or `no-possible-index`.
- *Deliberately heavy test* — bulk import, report export: an exception on the test
  (step 3), not a raised global budget.
- *False positive* — a rule judged a pattern it cannot see through: an exception on the
  test with a comment saying why.

**3. Exceptions — how to set them correctly:**

```php
use QueryGuard\Attribute\AllowQueries;
use QueryGuard\Attribute\IgnoreRule;

// a budget sized to the test's real work — its actual count is in the log
#[AllowQueries(120)]
public function testBulkImport(): void

// switch off exactly the rule that misjudges this test; the argument is the rule id
// verbatim from the finding header: [warning] n-plus-one — ...
#[IgnoreRule('n-plus-one')]
public function testLegacyPdfExport(): void

// a whole class may carry the attributes instead of repeating them per test
#[IgnoreRule('select-star')]
final class ReportQueryTest extends TestCase
```

A method-level `#[AllowQueries]` overrides a class-level one; `#[IgnoreRule]` is
repeatable and adds to whatever the class ignores. Keep exceptions narrow: one rule on
one method beats a class-wide ignore of three. An exception without a reason comment is
where the rot starts.

**4. When the log is clean, switch to `strict`:**

```xml
<parameter name="mode" value="strict"/>
```

On a new project the baseline is optional — every finding is fresh enough to fix. If the
suite ever outgrows that, the `baseline` parameter is right there.

### Keeping smoke/load tests out of query-guard

Performance smoke tests should run without the extension: the DBAL middleware and the
per-shape `EXPLAIN` of tier 2 distort the very timings those tests exist to measure, and
`strict` fails the run on a query budget that is the subject of the test, not a bug.

The setup is a group and a flag. **A second `phpunit.xml` is not needed** — PHPUnit's
`--no-extensions` switches every registered extension off for one run, so there is no
copy of the config to keep in sync.

**1. Put the tests in their own group:**

```php
use PHPUnit\Framework\Attributes\Group;

#[Group('smoke')]
final class AuctionBidLoadSmokeTest extends TestCase
```

**2. Exclude the group from normal runs** — either in the config, as a top-level
`<groups>` block (an `<exclude>` inside `<testsuite>` is a *path*, not a group):

```xml
<groups>
    <exclude>
        <group>smoke</group>
    </exclude>
</groups>
```

or on the command line: `vendor/bin/phpunit --exclude-group=smoke`. Both leave
`--group=smoke` working: an explicit group on the command line overrides the config.

**3. Wire both commands into composer:**

```json
{
    "scripts": {
        "test": "phpunit --exclude-group=smoke",
        "test:smoke": "phpunit --no-extensions --group=smoke"
    }
}
```

or into a Makefile:

```make
test:
	vendor/bin/phpunit --exclude-group=smoke

test-smoke:
	vendor/bin/phpunit --no-extensions --group=smoke
```

`--no-extensions` turns off *every* extension registered in `phpunit.xml`, not only this
one. If the smoke suite depends on another extension, that is the case — and the only
case — for a second configuration file; then porting every change of `phpunit.xml` into
it becomes a standing obligation, and a config that has silently drifted is worse than no
config at all.

One habit keeps the rest honest: run the smoke suite sequentially. Parallel workers race
each other over CPU and the database, and the measurements are worthless.

## Requirements

PHP 8.2+, PHPUnit 10.5 / 11 / 12 / 13.
Doctrine ORM 2 / 3 with DBAL 3 / 4, or Laravel 11+.
Tier 2: MySQL 8 / MariaDB or PostgreSQL.

## Development

No local PHP needed — everything runs in containers. `composer update` and not
`install`: this is a library, so `composer.lock` is deliberately not in the repository
and CI resolves dependencies afresh on every run.

```bash
docker run --rm -v "$PWD":/app -w /app -e COMPOSER_CACHE_DIR=/tmp/composer-cache composer:2 composer update
docker run --rm -v "$PWD":/app -w /app php:8.5-cli php vendor/bin/phpunit
docker run --rm -v "$PWD":/app -w /app php:8.5-cli php vendor/bin/phpstan analyse --memory-limit=1G
docker run --rm -v "$PWD":/app -w /app php:8.5-cli php vendor/bin/php-cs-fixer fix
```

Tier 2 is verified against a synthetic stand with 100 000 rows:

```bash
docker compose -f tools/stand/docker-compose.yml up -d
tools/stand/capture.sh          # refresh reference plans in tests/Fixture/Explain
tools/stand/run-tier2-tests.sh  # run tier 2 against live MySQL and PostgreSQL
docker compose -f tools/stand/docker-compose.yml down -v
```

The expected plan flags in those tests were written by hand from reading real `EXPLAIN`
output, never generated from our own parser — otherwise a misunderstanding of a plan would
land in the code and in its test at the same time.

## License

MIT. See [LICENSE](LICENSE).
