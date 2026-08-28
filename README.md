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
> covered by tests (121 of them, on PHPUnit 10.5–13, Doctrine ORM 2–3, DBAL 3–4, MySQL
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
| `baseline` | Path to the baseline file | not set |
| `n-plus-one-threshold` | Repeats before it counts as N+1 | `3` |
| `duplicate-threshold` | Repeats before it counts as a duplicate | `5` |
| `query-in-loop-threshold` | Queries from one place before it looks like a loop | `5` |
| `max-queries` | Query budget per test | not set, rule silent |
| `large-tables` | Comma-separated tables for `no-limit` | not set, rule silent |
| `select-star` | Enable `select-star` | `false` |
| `tier2` | Enable plan rules | `false` |
| `min-rows` | Table size below which plan rules do not judge | `1000` |

`strict` mode fails the whole run (exit code 1), not an individual test: PHPUnit's event
system gives an extension no way to mark a test as failed.

## Recipes

### Adopting on a legacy project

You cannot fix hundreds of old tests at once, and you do not need to. The goal is to draw
a line: everything existing goes into the baseline, everything new is checked. The steps
below go from install to `strict`.

**1. Install and enable every check.** Extension and adapter as in the Install section
above. Keep `mode="report"` while you are still measuring the damage, and set the
baseline path right away — step 4 writes to it:

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

`max-queries` here is a probe, not the budget you intend to keep — see step 3.

**2. Run the suite and save the log.** Findings and query counts go to stdout:

```bash
vendor/bin/phpunit 2>&1 | tee query-guard.log
```

**3. Pick the real `max-queries` from the log.** Every breach is printed as
`N queries against a budget of M`, so a low probe (say 20) and a high probe (say 100)
bracket the distribution; `grep 'against a budget' query-guard.log` lists the tests over
each line with their exact counts:

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

**4. Generate the baseline and commit it:**

```bash
QUERY_GUARD_GENERATE_BASELINE=1 vendor/bin/phpunit
```

Everything currently found lands in `tests/query-guard-baseline.json`; the file goes into
the repository.

**5. Decide on tier 2 — only if the tests run on a database with real volume.** Plan
rules need rows and statistics; on a three-row fixture database the optimiser's estimate
lies and the rules do more harm than good (see the Tier 2 section above). If your test
database is a production copy or is seeded at scale, add:

```xml
<parameter name="tier2" value="true"/>
```

and regenerate the baseline (step 4), so existing plan findings are recorded as well.

**6. Re-run and verify:**

```bash
vendor/bin/phpunit 2>&1 | tee query-guard-after.log
```

The summary must show `silenced by baseline: N` and stay otherwise empty — anything that
still appears is either a flaky callsite or a regression between the two runs, and the
baseline has nothing to do with it.

**7. Switch the mode to `strict`:**

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
justify in writing. Enable everything, including tier 2 as soon as the test database
carries real volume; keep `report` while triaging:

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
        <parameter name="large-tables" value="users,orders"/>
        <parameter name="tier2" value="true"/>
    </bootstrap>
</extensions>
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
`strict` fails the run on a query budget that is the subject of the test, not a bug. The
setup: a group, a separate config, no extension in it.

**1. Put the tests in their own group:**

```php
use PHPUnit\Framework\Attributes\Group;

#[Group('smoke')]
final class AuctionBidLoadSmokeTest extends TestCase
```

**2. Exclude the group from normal runs**, so the parallel suite does not race the load
measurements over CPU and database — either in the config:

```xml
<testsuite name="default">
    <directory>tests</directory>
    <exclude>
        <group>smoke</group>
    </exclude>
</testsuite>
```

or on the command line: `vendor/bin/phpunit --exclude-group=smoke`.

**3. Add `phpunit.smoke.xml`** — a copy of the main config with the
`<bootstrap class="QueryGuard\Extension">` block removed and nothing else changed: same
testsuites, same bootstrap file, same PHP settings.

**4. Wire both commands into composer:**

```json
{
    "scripts": {
        "test": "phpunit --exclude-group=smoke",
        "test:smoke": "phpunit --configuration=phpunit.smoke.xml --group=smoke"
    }
}
```

or into a Makefile:

```make
test:
	vendor/bin/phpunit --exclude-group=smoke

test-smoke:
	vendor/bin/phpunit --configuration=phpunit.smoke.xml --group=smoke
```

Two habits keep this honest: run the smoke suite sequentially — parallel workers destroy
the measurements — and port every change of `phpunit.xml` into `phpunit.smoke.xml`; a
config that has silently drifted is worse than no config at all.

## Requirements

PHP 8.2+, PHPUnit 10.5 / 11 / 12 / 13.
Doctrine ORM 2 / 3 with DBAL 3 / 4, or Laravel 11+.
Tier 2: MySQL 8 / MariaDB or PostgreSQL.

## Development

No local PHP needed — everything runs in containers:

```bash
docker run --rm -v "$PWD":/app -w /app -e COMPOSER_CACHE_DIR=/tmp/composer-cache composer:2 composer install
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
