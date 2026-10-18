<?php

declare(strict_types=1);

namespace QueryGuard\Platform;

/**
 * MySQL and MariaDB: `EXPLAIN FORMAT=JSON`.
 *
 * MariaDB returns a similar but not identical JSON, which is why the tree walk tolerates
 * missing keys instead of following a strict schema.
 *
 * @internal
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

        if ([] === $nodes) {
            // a valid answer without a single table: the optimiser settled the query
            // before reading one — `no matching row in const table` (a unique-key lookup
            // that found nothing), `No tables used`, `Impossible WHERE`, `No matching
            // min/max row`. Seen 84 times in 692 plans on two live projects
            $message = Json::string($block, 'message');

            return null === $message ? Plan::empty($this->name()) : Plan::readingNoTable($this->name(), $message);
        }

        return new Plan($this->name(), $nodes);
    }

    /**
     * MySQL puts `using_filesort` and `using_temporary_table` on the
     * `ordering_operation` / `grouping_operation` node rather than on the table itself —
     * observed on the stand. So the flags have to be carried down the tree.
     *
     * @param array<array-key, mixed> $node
     * @param list<PlanNode>          $nodes
     * @param bool                    $joined the node is one of several tables of a
     *                                        `nested_loop` — see node()
     */
    private function walk(array $node, array &$nodes, bool $filesort, bool $temporary, bool $joined = false): void
    {
        // a subquery entry (`optimized_away_subqueries`, `attached_subqueries`, a union
        // member) wraps its own `query_block`
        $block = Json::object($node, 'query_block');

        if ([] !== $block) {
            $this->walk($block, $nodes, $filesort, $temporary);
        }

        $filesort = $filesort || Json::bool($node, 'using_filesort');
        $temporary = $temporary || Json::bool($node, 'using_temporary_table');

        $table = Json::object($node, 'table');

        if ([] !== $table) {
            $this->table($table, $nodes, $filesort, $temporary, $joined);
        }

        foreach (['ordering_operation', 'grouping_operation', 'duplicates_removal', 'union_result'] as $key) {
            $child = Json::object($node, $key);

            if ([] !== $child) {
                $this->walk($child, $nodes, $filesort, $temporary);
            }
        }

        $loop = Json::objects($node, 'nested_loop');

        foreach ($loop as $child) {
            $this->walk($child, $nodes, $filesort, $temporary, \count($loop) > 1);
        }

        foreach (['query_specifications', 'optimized_away_subqueries', 'windows'] as $key) {
            foreach (Json::objects($node, $key) as $child) {
                $this->walk($child, $nodes, $filesort, $temporary);
            }
        }
    }

    /**
     * A table entry, and whatever subqueries hang off it.
     *
     * A derived table (`JOIN (SELECT ...) x`, Doctrine's paginator `dctrn_result`) keeps
     * its subquery inside the table entry under `materialized_from_subquery`, so that is
     * where the walk has to go to see the tables the subquery actually reads. It starts
     * with fresh flags: the `using_temporary_table` on a materialisation is the
     * materialisation itself, not a `GROUP BY`, and the outer sort does not sort the
     * inner rows.
     *
     * When the subquery is settled before the outer query reads anything, MySQL leaves a
     * table entry with no `table_name` at all — only the subquery. That entry used to
     * surface as a table called "unknown"; it is not a table, and no node is made for it.
     *
     * @param array<array-key, mixed> $table
     * @param list<PlanNode>          $nodes
     */
    private function table(array $table, array &$nodes, bool $filesort, bool $temporary, bool $joined): void
    {
        $subquery = Json::object($table, 'materialized_from_subquery');

        if (null !== Json::string($table, 'table_name')) {
            $nodes[] = $this->node($table, $filesort, $temporary, [] !== $subquery, $joined);
        }

        if ([] !== $subquery) {
            $this->walk($subquery, $nodes, false, false);
        }

        foreach (Json::objects($table, 'attached_subqueries') as $child) {
            $this->walk($child, $nodes, false, false);
        }
    }

    /**
     * @param array<array-key, mixed> $table
     */
    private function node(array $table, bool $filesort, bool $temporary, bool $derived, bool $joined): PlanNode
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
            derived: $derived,
            hasCondition: self::hasCondition($table, $joined),
        );
    }

    /**
     * A table read with no condition on it carries no `attached_condition`; a key lookup
     * (`ref`) applies its condition through the key, and `index_condition` is one pushed
     * down into the index.
     *
     * **Only a table read alone can be said to have none.** In a join the condition lives
     * on whichever table the optimiser reads second: `big b JOIN child c ON c.big_id =
     * b.id` with no index on `child.big_id` drives from `c` by a full scan and puts
     * `c.big_id = b.id` on `b` as a key lookup. The scan of `c` is exactly the missing
     * index, and `c` shows no condition — so in a join "none" is "cannot tell" (observed
     * on the stand).
     *
     * @param array<array-key, mixed> $table
     */
    private static function hasCondition(array $table, bool $joined): ?bool
    {
        if (isset($table['attached_condition']) || isset($table['index_condition']) || isset($table['ref'])) {
            return true;
        }

        return $joined ? null : false;
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
