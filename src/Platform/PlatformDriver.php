<?php

declare(strict_types=1);

namespace QueryGuard\Platform;

/**
 * Axis 2: `EXPLAIN` syntax and plan parsing.
 *
 * Independent of the ORM. Doctrine on PostgreSQL and Eloquent on PostgreSQL share the
 * same plan parsing — mixing the two axes up would produce four implementations instead
 * of two plus two.
 */
interface PlatformDriver
{
    /**
     * Platform name in adapter terms: `mysql`, `mariadb`, `pgsql`, `sqlite`.
     */
    public static function supports(string $platform): bool;

    public function name(): string;

    /**
     * `EXPLAIN FORMAT=JSON <sql>` versus `EXPLAIN (FORMAT JSON) <sql>` — the platforms
     * diverge even here.
     */
    public function explainSql(string $sql): string;

    /**
     * @param string $raw EXPLAIN output exactly as the database returned it
     */
    public function parsePlan(string $raw): Plan;

    /**
     * Whether the platform answers the question "which indexes could have applied".
     *
     * MySQL does (`possible_keys`), PostgreSQL does not. This is not a detail: on
     * PostgreSQL the `no-possible-index` rule has to say explicitly that it cannot judge
     * rather than report green. Silent degradation is exactly how a tool loses trust.
     */
    public function reportsPossibleIndexes(): bool;

    /**
     * What `PlanNode::estimatedRows` means on this platform.
     *
     * MySQL reports `rows_examined_per_scan` — how many rows the node will SCAN.
     * PostgreSQL reports `Plan Rows` — how many it will RETURN after filtering. The
     * difference matters: for `SELECT ... WHERE plain_col = 42` over a 100 000-row table
     * MySQL says 99 989 while PostgreSQL says 100. You cannot judge "a large table" by
     * the second number.
     */
    public function estimatesScannedRows(): bool;

    /**
     * Whether the platform has a notion of a "temporary table" worth warning about.
     *
     * On MySQL `Using temporary` is a signal. On PostgreSQL the closest equivalent,
     * `HashAggregate`, is a normal and fast way to group, and complaining about it would
     * mean producing false positives.
     */
    public function reportsTemporaryTable(): bool;

    /**
     * A query returning an estimated row count for a table (one parameter: the table
     * name).
     *
     * Needed where the plan itself does not answer "is this table large". `null` means
     * the platform's plan estimate is enough.
     */
    public function relationSizeSql(): ?string;
}
