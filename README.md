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

## Requirements

PHP 8.2+, PHPUnit 10.5 / 11 / 12 / 13.
Doctrine ORM 2 / 3 with DBAL 3 / 4, or Laravel 11+.
Tier 2: MySQL 8 / MariaDB or PostgreSQL.

## Development

No local PHP needed — everything runs in containers:

```bash
./dev.sh composer install
QG_IMAGE=php:8.5-cli ./dev.sh php vendor/bin/phpunit
QG_IMAGE=php:8.5-cli ./dev.sh php vendor/bin/phpstan analyse --memory-limit=1G
QG_IMAGE=php:8.5-cli ./dev.sh php vendor/bin/php-cs-fixer fix
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
