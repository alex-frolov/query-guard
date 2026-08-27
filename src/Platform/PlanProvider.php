<?php

declare(strict_types=1);

namespace QueryGuard\Platform;

use QueryGuard\Adapter\Explainer;
use QueryGuard\Collector\DefaultQueryCollector;
use QueryGuard\Query\QueryEvent;

/**
 * Fetches a query plan — once per distinct fingerprint per run.
 *
 * The cache is not an optimisation but a condition of being usable at all: without it a
 * suite issuing 25 000 queries would trigger 25 000 EXPLAINs and double its own runtime.
 */
final class PlanProvider
{
    /** @var array<string, Plan|null> */
    private array $cache = [];

    private int $explained = 0;

    private int $failed = 0;

    /** @var array<string, int|null> */
    private array $relationSizes = [];

    public function __construct(
        private readonly Explainer $explainer,
        private readonly PlatformDriver $driver,
        private readonly ?DefaultQueryCollector $collector = null,
    ) {
    }

    public function driver(): PlatformDriver
    {
        return $this->driver;
    }

    public function planFor(QueryEvent $event): ?Plan
    {
        if (!$event->isSelect()) {
            // MySQL will happily EXPLAIN an INSERT/UPDATE, but it tells our rules
            // nothing — and on some platforms it would touch the data
            return null;
        }

        $key = $event->fingerprint()->value();

        if (\array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        return $this->cache[$key] = $this->explain($event);
    }

    /**
     * How many rows the node actually reads.
     *
     * MySQL answers this directly (`rows_examined_per_scan`); PostgreSQL does not — its
     * `Plan Rows` counts rows returned after filtering, so a sequential scan of a
     * 100 000-row table can report 100. For PostgreSQL the size is therefore taken from
     * the catalog, once per table per run.
     */
    public function rowsFor(PlanNode $node): ?int
    {
        if ($this->driver->estimatesScannedRows()) {
            return $node->estimatedRows;
        }

        $sql = $this->driver->relationSizeSql();

        if (null === $sql) {
            return $node->estimatedRows;
        }

        if (\array_key_exists($node->table, $this->relationSizes)) {
            return $this->relationSizes[$node->table];
        }

        $this->collector?->pause();

        try {
            $rows = $this->explainer->run($sql, [1 => $node->table]);
            $value = \is_array($rows[0] ?? null) ? reset($rows[0]) : null;

            return $this->relationSizes[$node->table] = is_numeric($value) ? (int) $value : null;
        } catch (\Throwable) {
            return $this->relationSizes[$node->table] = null;
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

    private function explain(QueryEvent $event): ?Plan
    {
        $this->collector?->pause();

        try {
            $rows = $this->explainer->run($this->driver->explainSql($event->sql), $event->params);
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

        $plan = $this->driver->parsePlan($raw);

        if ($plan->isEmpty()) {
            ++$this->failed;

            return null;
        }

        ++$this->explained;

        return $plan;
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
