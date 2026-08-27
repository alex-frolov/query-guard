<?php

declare(strict_types=1);

namespace QueryGuard\Test\Unit\Platform;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use QueryGuard\Platform\MySqlPlatformDriver;
use QueryGuard\Platform\Plan;
use QueryGuard\Platform\PlanNode;
use QueryGuard\Platform\PostgresPlatformDriver;
use QueryGuard\Platform\ScanType;

/**
 * Parsing of real plans captured from the stand in `tools/stand` (100 000 rows in `big`,
 * 50 000 in `child`).
 *
 * **The expectations were written by hand from reading the JSON, never recorded from our
 * own parser's output.** Otherwise a misunderstanding of a plan would land in the code
 * and in its test at the same time.
 */
#[CoversClass(MySqlPlatformDriver::class)]
#[CoversClass(PostgresPlatformDriver::class)]
#[CoversClass(Plan::class)]
#[CoversClass(PlanNode::class)]
final class PlanParsingTest extends TestCase
{
    // --- MySQL --------------------------------------------------------------

    /**
     * `SELECT id, name FROM big WHERE plain_col = 42` — there is no index on `plain_col`.
     * In the plan: access_type ALL, no key, no `possible_keys` at all, 99 989 rows.
     */
    public function testMySqlFullScanWithoutAnyCandidateIndex(): void
    {
        $node = $this->mysql('no-index')->nodes[0];

        self::assertSame('big', $node->table);
        self::assertSame(ScanType::FullTable, $node->scanType);
        self::assertFalse($node->usesIndex());
        self::assertSame([], $node->possibleIndexes);
        self::assertTrue($node->hasNoPossibleIndex());
        self::assertSame(99989, $node->estimatedRows);
    }

    /**
     * `WHERE indexed_col = 42` — access_type ref, key `idx_big_indexed`, 100 rows.
     */
    public function testMySqlIndexLookup(): void
    {
        $node = $this->mysql('indexed')->nodes[0];

        self::assertSame(ScanType::Lookup, $node->scanType);
        self::assertSame('idx_big_indexed', $node->usedIndex);
        self::assertSame(['idx_big_indexed'], $node->possibleIndexes);
        self::assertFalse($node->hasNoPossibleIndex());
        self::assertSame(100, $node->estimatedRows);
    }

    /**
     * MySQL puts `using_filesort` on the `ordering_operation` node rather than on the
     * table. Without carrying the flag down the tree the `filesort` rule would never fire.
     */
    public function testMySqlFilesortFlagLivesOnTheParentNode(): void
    {
        $node = $this->mysql('filesort')->nodes[0];

        self::assertTrue($node->filesort);
        self::assertSame(ScanType::Lookup, $node->scanType, 'the fetch itself still uses the index');
    }

    /**
     * `GROUP BY plain_col`: `using_temporary_table` sits on the `grouping_operation` node.
     */
    public function testMySqlTemporaryTableFlagLivesOnTheParentNode(): void
    {
        $node = $this->mysql('group-by')->nodes[0];

        self::assertTrue($node->temporaryTable);
        self::assertSame(ScanType::FullTable, $node->scanType);
    }

    /**
     * A JOIN with no index on `child.big_id`: two nodes — a full scan of `c` and an
     * eq_ref by the primary key of `b`.
     */
    public function testMySqlJoinGivesTwoNodesInExecutionOrder(): void
    {
        $plan = $this->mysql('join-no-index');

        self::assertCount(2, $plan->nodes);
        self::assertSame('c', $plan->nodes[0]->table);
        self::assertSame(ScanType::FullTable, $plan->nodes[0]->scanType);
        self::assertSame(49931, $plan->nodes[0]->estimatedRows);
        self::assertSame('b', $plan->nodes[1]->table);
        self::assertSame(ScanType::Lookup, $plan->nodes[1]->scanType);
        self::assertSame('PRIMARY', $plan->nodes[1]->usedIndex);
    }

    /**
     * A trap: the plan has no `possible_keys`, yet an index is used. An empty candidate
     * list on its own does NOT mean "no index exists".
     */
    public function testMySqlFullIndexScanIsNotReportedAsMissingIndex(): void
    {
        $node = $this->mysql('full-index-scan')->nodes[0];

        self::assertSame(ScanType::FullIndex, $node->scanType);
        self::assertSame('idx_big_indexed', $node->usedIndex);
        self::assertSame([], $node->possibleIndexes);
        self::assertFalse($node->hasNoPossibleIndex());
    }

    public function testMySqlReportsCost(): void
    {
        self::assertSame(10287.90, $this->mysql('no-index')->cost);
    }

    // --- PostgreSQL ---------------------------------------------------------

    public function testPostgresSequentialScan(): void
    {
        $node = $this->postgres('no-index')->nodes[0];

        self::assertSame('big', $node->table);
        self::assertSame(ScanType::FullTable, $node->scanType);
        self::assertNull($node->possibleIndexes, 'PostgreSQL does not report which indexes could apply');
        self::assertNull($node->hasNoPossibleIndex());
    }

    /**
     * `Plan Rows` in PostgreSQL counts rows RETURNED. On the same query where MySQL
     * reports 99 989 rows scanned, PostgreSQL reports 100 returned.
     */
    public function testPostgresRowsMeanSomethingElseThanInMySql(): void
    {
        self::assertSame(100, $this->postgres('no-index')->nodes[0]->estimatedRows);
        self::assertSame(99989, $this->mysql('no-index')->nodes[0]->estimatedRows);
        self::assertFalse((new PostgresPlatformDriver())->estimatesScannedRows());
        self::assertTrue((new MySqlPlatformDriver())->estimatesScannedRows());
    }

    /**
     * The index name of a `Bitmap Heap Scan` sits on its child `Bitmap Index Scan`,
     * which in turn carries no table name.
     */
    public function testPostgresBitmapScanTakesIndexNameFromItsChild(): void
    {
        $node = $this->postgres('indexed')->nodes[0];

        self::assertSame('big', $node->table);
        self::assertSame(ScanType::Range, $node->scanType);
        self::assertSame('idx_big_indexed', $node->usedIndex);
    }

    /**
     * In PostgreSQL sorting is a separate node ABOVE the scan, not a flag on it.
     */
    public function testPostgresSortIsCarriedDownToTheScan(): void
    {
        $node = $this->postgres('filesort')->nodes[0];

        self::assertSame('big', $node->table);
        self::assertTrue($node->filesort);
    }

    /**
     * An `Index Only Scan` without an `Index Cond` walks the whole index.
     */
    public function testPostgresIndexScanWithoutConditionIsAFullIndexScan(): void
    {
        $node = $this->postgres('full-index-scan')->nodes[0];

        self::assertSame(ScanType::FullIndex, $node->scanType);
        self::assertSame('idx_big_indexed', $node->usedIndex);
    }

    /**
     * PostgreSQL's `HashAggregate` is a normal way to group, not a signal. Complaining
     * about it would mean producing false positives.
     */
    public function testPostgresNeverFlagsTemporaryTables(): void
    {
        foreach ($this->postgres('group-by')->nodes as $node) {
            self::assertFalse($node->temporaryTable);
        }

        self::assertFalse((new PostgresPlatformDriver())->reportsTemporaryTable());
    }

    public function testPostgresJoinKeepsBothRelations(): void
    {
        $tables = array_map(
            static fn (PlanNode $node): string => $node->table,
            $this->postgres('join-no-index')->nodes,
        );

        self::assertSame(['child', 'big'], $tables);
    }

    // --- shared -------------------------------------------------------------

    public function testGarbageInsteadOfPlanGivesAnEmptyPlanNotAnError(): void
    {
        self::assertTrue((new MySqlPlatformDriver())->parsePlan('not json')->isEmpty());
        self::assertTrue((new PostgresPlatformDriver())->parsePlan('[]')->isEmpty());
    }

    public function testExplainSyntaxDiffersBetweenPlatforms(): void
    {
        self::assertSame('EXPLAIN FORMAT=JSON SELECT 1', (new MySqlPlatformDriver())->explainSql('SELECT 1'));
        self::assertSame('EXPLAIN (FORMAT JSON) SELECT 1', (new PostgresPlatformDriver())->explainSql('SELECT 1'));
    }

    private function mysql(string $fixture): Plan
    {
        return (new MySqlPlatformDriver())->parsePlan($this->raw('mysql', $fixture));
    }

    private function postgres(string $fixture): Plan
    {
        return (new PostgresPlatformDriver())->parsePlan($this->raw('pgsql', $fixture));
    }

    private function raw(string $platform, string $fixture): string
    {
        $path = \dirname(__DIR__, 2).'/Fixture/Explain/'.$platform.'/'.$fixture.'.json';

        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
