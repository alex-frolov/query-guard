<?php

declare(strict_types=1);

namespace QueryGuard\Platform;

use QueryGuard\Adapter\AdapterSet;
use QueryGuard\Collector\DefaultQueryCollector;
use QueryGuard\Query\QueryEvent;

/**
 * Fetches a query plan — once per distinct fingerprint per connection per run.
 *
 * **Per connection, not per run.** `EXPLAIN` has to go through the connection that ran
 * the query: test harnesses such as `dama/doctrine-test-bundle` keep each test inside a
 * transaction that is rolled back, so the data exists nowhere else. Holding a single
 * explainer meant a project with two databases got the plan of one read against the
 * other, parsed with the other's platform driver — and a secondary connection on an
 * unsupported platform switched tier 2 off for the whole run.
 *
 * The cache is not an optimisation but a condition of being usable at all: without it a
 * suite issuing 25 000 queries would trigger 25 000 EXPLAINs and double its own runtime.
 */
final class PlanProvider
{
    /** @var array<string, Plan|null> keyed by connection and fingerprint */
    private array $cache = [];

    /** @var array<string, PlanSource|null> resolved once per connection name */
    private array $sources = [];

    /** @var array<string, string> connections skipped, and the platform that made them so */
    private array $unsupported = [];

    private int $explained = 0;

    private int $failed = 0;

    /** @var array<string, int|null> keyed by connection and table */
    private array $relationSizes = [];

    public function __construct(
        private readonly AdapterSet $adapters,
        private readonly ?DefaultQueryCollector $collector = null,
    ) {
    }

    public function planFor(QueryEvent $event): ?Plan
    {
        if (!$event->isSelect()) {
            // MySQL will happily EXPLAIN an INSERT/UPDATE, but it tells our rules
            // nothing — and on some platforms it would touch the data
            return null;
        }

        $source = $this->sourceFor($event->connection);

        if (null === $source) {
            return null;
        }

        $key = $event->connection.'|'.$event->fingerprint()->value();

        if (\array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        return $this->cache[$key] = $this->explain($event, $source);
    }

    /**
     * The driver that read this plan, so a rule can ask what its platform is able to
     * report before treating silence as "all clear".
     */
    public function driverFor(Plan $plan): ?PlatformDriver
    {
        return $this->sourceFor($plan->connection)?->driver;
    }

    /**
     * How many rows the node actually reads.
     *
     * MySQL answers this directly (`rows_examined_per_scan`); PostgreSQL does not — its
     * `Plan Rows` counts rows returned after filtering, so a sequential scan of a
     * 100 000-row table can report 100. For PostgreSQL the size is therefore taken from
     * the catalog, once per table per connection per run.
     */
    public function rowsFor(PlanNode $node, Plan $plan): ?int
    {
        $source = $this->sourceFor($plan->connection);

        if (null === $source || $source->driver->estimatesScannedRows()) {
            return $node->estimatedRows;
        }

        $sql = $source->driver->relationSizeSql();

        if (null === $sql) {
            return $node->estimatedRows;
        }

        // the same table name in two databases is two different tables
        $key = $plan->connection.'|'.$node->table;

        if (\array_key_exists($key, $this->relationSizes)) {
            return $this->relationSizes[$key];
        }

        $this->collector?->pause();

        try {
            $rows = $source->explainer->run($sql, [1 => $node->table]);
            $value = \is_array($rows[0] ?? null) ? reset($rows[0]) : null;

            return $this->relationSizes[$key] = is_numeric($value) ? (int) $value : null;
        } catch (\Throwable) {
            return $this->relationSizes[$key] = null;
        } finally {
            $this->collector?->resume();
        }
    }

    public function explained(): int
    {
        return $this->explained;
    }

    public function failed(): int
    {
        return $this->failed;
    }

    /**
     * The distinct drivers that actually read a plan during this run.
     *
     * Tier 2's caveats — "this platform cannot say which indexes could have applied" —
     * belong to the platforms that were really used, not to whichever one happened to
     * connect first.
     *
     * @return array<string, PlatformDriver> keyed by driver name
     */
    public function driversUsed(): array
    {
        $drivers = [];

        foreach ($this->sources as $source) {
            if (null !== $source) {
                $drivers[$source->driver->name()] = $source->driver;
            }
        }

        return $drivers;
    }

    /**
     * Connections whose queries were left unexplained, and why.
     *
     * Never silently: a connection that tier 2 could not look at has to be named, or
     * "no findings" quietly starts to mean "nobody looked".
     *
     * @return array<string, string> connection name => the platform that is not supported
     */
    public function unsupportedConnections(): array
    {
        return $this->unsupported;
    }

    public function sawAnyConnection(): bool
    {
        foreach ($this->sources as $source) {
            if (null !== $source) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve a connection name to the pair that can explain it.
     *
     * The verdict is remembered, including a negative one. A connection is registered by
     * the adapter as it opens, and an event carrying its name cannot exist before that —
     * so a name still missing here belongs to an ORM with no tier 2 support at all
     * (Eloquent), and asking again on every query would only cost.
     */
    private function sourceFor(string $connection): ?PlanSource
    {
        if (\array_key_exists($connection, $this->sources)) {
            return $this->sources[$connection];
        }

        $explainer = $this->adapters->explainers()[$connection] ?? null;

        if (null === $explainer) {
            return $this->sources[$connection] = null;
        }

        $driver = PlatformDrivers::for($explainer->platform());

        if (null === $driver) {
            $this->unsupported[$connection] = $explainer->platform();

            return $this->sources[$connection] = null;
        }

        return $this->sources[$connection] = new PlanSource($explainer, $driver);
    }

    private function explain(QueryEvent $event, PlanSource $source): ?Plan
    {
        $this->collector?->pause();

        try {
            $rows = $source->explainer->run($source->driver->explainSql($event->sql), $event->params);
        } catch (\Throwable) {
            // not "quietly green": failures are counted and reach the summary
            ++$this->failed;

            return null;
        } finally {
            $this->collector?->resume();
        }

        $raw = self::firstValue($rows);

        if (null === $raw) {
            ++$this->failed;

            return null;
        }

        $plan = $source->driver->parsePlan($raw);

        if ($plan->isEmpty()) {
            ++$this->failed;

            return null;
        }

        ++$this->explained;

        return $plan->onConnection($event->connection);
    }

    /**
     * MySQL puts the JSON in a column called `EXPLAIN`, PostgreSQL in `QUERY PLAN`.
     *
     * @param list<array<string, mixed>> $rows
     */
    private static function firstValue(array $rows): ?string
    {
        $first = $rows[0] ?? null;

        if (!\is_array($first) || [] === $first) {
            return null;
        }

        $value = reset($first);

        return \is_string($value) ? $value : null;
    }
}
