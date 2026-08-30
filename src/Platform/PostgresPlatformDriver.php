<?php

declare(strict_types=1);

namespace QueryGuard\Platform;

/**
 * PostgreSQL: `EXPLAIN (FORMAT JSON)`.
 *
 * PostgreSQL is a first-class platform here, not an afterthought: a large part of the
 * Symfony/Doctrine world runs on it, and its absence elsewhere is half of why this
 * package exists.
 *
 * PostgreSQL reports nothing resembling `possible_keys`: it has no notion of "indexes
 * that could have applied but were not chosen". Hence `reportsPossibleIndexes()` is
 * `false` here, and the `no-possible-index` rule has to say it cannot judge.
 */
final class PostgresPlatformDriver implements PlatformDriver
{
    public static function supports(string $platform): bool
    {
        return \in_array($platform, ['pgsql', 'postgres', 'postgresql'], true);
    }

    public function name(): string
    {
        return 'pgsql';
    }

    public function explainSql(string $sql): string
    {
        return 'EXPLAIN (FORMAT JSON) '.$sql;
    }

    public function reportsPossibleIndexes(): bool
    {
        return false;
    }

    public function estimatesScannedRows(): bool
    {
        return false;
    }

    public function reportsTemporaryTable(): bool
    {
        return false;
    }

    public function relationSizeSql(): string
    {
        // `Plan Rows` counts rows returned, not rows scanned, so the table size has to
        // be asked for separately.
        //
        // Resolved through `to_regclass` rather than `WHERE relname = ?`: `relname` is
        // unique only within a schema, so a plain name match picks an arbitrary one of
        // the same-named tables — and `search_path` is exactly what decides which table
        // the query under study actually read.
        return 'SELECT reltuples::bigint AS rows FROM pg_class WHERE oid = to_regclass(?)';
    }

    public function parsePlan(string $raw): Plan
    {
        try {
            $decoded = json_decode($raw, true, 64, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return Plan::empty($this->name());
        }

        // EXPLAIN (FORMAT JSON) returns an array with a single element
        $first = \is_array($decoded) ? ($decoded[0] ?? $decoded) : null;
        $root = Json::object($first, 'Plan');

        if ([] === $root) {
            return Plan::empty($this->name());
        }

        $nodes = [];
        $this->walk($root, $nodes, false);

        return new Plan($this->name(), $nodes, Json::float($root, 'Total Cost'));
    }

    /**
     * @param array<array-key, mixed> $node
     * @param list<PlanNode>          $nodes
     * @param bool                    $underSort in PostgreSQL sorting is a separate node
     *                                           above the scan, not a flag on it
     */
    private function walk(array $node, array &$nodes, bool $underSort): void
    {
        $type = Json::string($node, 'Node Type') ?? '';
        $sorting = $underSort || 'Sort' === $type || 'Incremental Sort' === $type;

        $relation = Json::string($node, 'Relation Name');

        if (null !== $relation) {
            $nodes[] = new PlanNode(
                table: $relation,
                scanType: self::scanType($type, isset($node['Index Cond'])),
                usedIndex: self::indexName($node),
                // PostgreSQL does not answer "which indexes could have applied"
                possibleIndexes: null,
                estimatedRows: Json::int($node, 'Plan Rows'),
                filesort: $sorting,
                // no temporary tables in the MySQL sense here — see reportsTemporaryTable()
                temporaryTable: false,
            );
        }

        foreach (Json::objects($node, 'Plans') as $child) {
            $this->walk($child, $nodes, $sorting);
        }
    }

    /**
     * An `Index Scan` without an `Index Cond` walks the whole index rather than looking
     * anything up. The Node Type is the same either way; only the condition tells them apart.
     */
    private static function scanType(string $type, bool $hasIndexCondition): ScanType
    {
        return match ($type) {
            'Seq Scan' => ScanType::FullTable,
            'Index Scan', 'Index Only Scan' => $hasIndexCondition ? ScanType::Lookup : ScanType::FullIndex,
            'Bitmap Heap Scan' => ScanType::Range,
            'Tid Scan' => ScanType::Constant,
            default => ScanType::Unknown,
        };
    }

    /**
     * A `Bitmap Heap Scan` does not carry the index name; its child `Bitmap Index Scan`
     * does — and that child, in turn, carries no table name.
     *
     * @param array<array-key, mixed> $node
     */
    private static function indexName(array $node): ?string
    {
        $own = Json::string($node, 'Index Name');

        if (null !== $own) {
            return $own;
        }

        foreach (Json::objects($node, 'Plans') as $child) {
            if ('Bitmap Index Scan' === Json::string($child, 'Node Type')) {
                $name = Json::string($child, 'Index Name');

                if (null !== $name) {
                    return $name;
                }
            }
        }

        return null;
    }
}
