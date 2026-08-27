<?php

declare(strict_types=1);

namespace QueryGuard\Platform;

/**
 * MySQL and MariaDB: `EXPLAIN FORMAT=JSON`.
 *
 * MariaDB returns a similar but not identical JSON, which is why the tree walk tolerates
 * missing keys instead of following a strict schema.
 */
final class MySqlPlatformDriver implements PlatformDriver
{
    public static function supports(string $platform): bool
    {
        return \in_array($platform, ['mysql', 'mariadb'], true);
    }

    public function name(): string
    {
        return 'mysql';
    }

    public function explainSql(string $sql): string
    {
        return 'EXPLAIN FORMAT=JSON '.$sql;
    }

    public function reportsPossibleIndexes(): bool
    {
        return true;
    }

    public function estimatesScannedRows(): bool
    {
        return true;
    }

    public function reportsTemporaryTable(): bool
    {
        return true;
    }

    public function relationSizeSql(): ?string
    {
        // not needed: `rows_examined_per_scan` already is the scan estimate
        return null;
    }

    public function parsePlan(string $raw): Plan
    {
        try {
            $decoded = json_decode($raw, true, 64, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return Plan::empty($this->name());
        }

        $block = Json::object($decoded, 'query_block');

        if ([] === $block) {
            return Plan::empty($this->name());
        }

        $nodes = [];
        $this->walk($block, $nodes, false, false);

        return new Plan($this->name(), $nodes, Json::float(Json::object($block, 'cost_info'), 'query_cost'));
    }

    /**
     * MySQL puts `using_filesort` and `using_temporary_table` on the
     * `ordering_operation` / `grouping_operation` node rather than on the table itself —
     * observed on the stand. So the flags have to be carried down the tree.
     *
     * @param array<array-key, mixed> $node
     * @param list<PlanNode>          $nodes
     */
    private function walk(array $node, array &$nodes, bool $filesort, bool $temporary): void
    {
        $filesort = $filesort || Json::bool($node, 'using_filesort');
        $temporary = $temporary || Json::bool($node, 'using_temporary_table');

        $table = Json::object($node, 'table');

        if ([] !== $table) {
            $nodes[] = $this->node($table, $filesort, $temporary);
        }

        foreach (['ordering_operation', 'grouping_operation', 'duplicates_removal', 'materialized_from_subquery'] as $key) {
            $child = Json::object($node, $key);

            if ([] !== $child) {
                $this->walk($child, $nodes, $filesort, $temporary);
            }
        }

        foreach (['nested_loop', 'query_specifications', 'optimized_away_subqueries', 'windows'] as $key) {
            foreach (Json::objects($node, $key) as $child) {
                $this->walk($child, $nodes, $filesort, $temporary);
            }
        }
    }

    /**
     * @param array<array-key, mixed> $table
     */
    private function node(array $table, bool $filesort, bool $temporary): PlanNode
    {
        return new PlanNode(
            table: Json::string($table, 'table_name') ?? 'unknown',
            scanType: self::scanType(Json::string($table, 'access_type')),
            usedIndex: Json::string($table, 'key'),
            // in MySQL's JSON plan the `possible_keys` key is simply absent when no
            // index applied (observed on the stand: `WHERE plain_col = 42` against a
            // table with no index). An empty list here means exactly that, not "unknown"
            possibleIndexes: Json::strings($table, 'possible_keys'),
            estimatedRows: Json::int($table, 'rows_examined_per_scan'),
            filesort: $filesort || Json::bool($table, 'using_filesort'),
            temporaryTable: $temporary || Json::bool($table, 'using_temporary_table'),
        );
    }

    private static function scanType(?string $accessType): ScanType
    {
        return match ($accessType) {
            'ALL' => ScanType::FullTable,
            'index' => ScanType::FullIndex,
            'range' => ScanType::Range,
            'ref', 'eq_ref', 'ref_or_null', 'index_merge' => ScanType::Lookup,
            'const', 'system' => ScanType::Constant,
            default => ScanType::Unknown,
        };
    }
}
