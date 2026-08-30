<?php

declare(strict_types=1);

namespace QueryGuard\Test\Unit\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use QueryGuard\Adapter\Doctrine\DoctrineEnricher;
use QueryGuard\Finding\Finding;
use QueryGuard\Finding\Severity;
use QueryGuard\Query\CallsiteResolver;
use QueryGuard\Query\QueryEvent;
use QueryGuard\Query\Trace;
use QueryGuard\Rule\NPlusOneRule;
use QueryGuard\TestIdentifier;
use QueryGuard\TestOptions;

#[CoversClass(NPlusOneRule::class)]
final class NPlusOneRuleTest extends TestCase
{
    public function testClassicNPlusOneIsFound(): void
    {
        $trace = $this->trace();
        $trace->record($this->event('SELECT * FROM orders', [], '/app/src/Report.php', 10));

        foreach ([1, 2, 3, 4, 5] as $id) {
            $trace->record($this->event('SELECT * FROM customers WHERE id = ?', [$id], '/app/src/Report.php', 14));
        }

        $findings = $this->findingsFor($trace);

        self::assertCount(1, $findings);
        self::assertSame('n-plus-one', $findings[0]->rule);
        // without a hint from the adapter only the shape heuristic remains — warning, not error
        self::assertSame(Severity::Warning, $findings[0]->severity);
        self::assertSame(5, $findings[0]->count);
        self::assertNotNull($findings[0]->callsite);
        self::assertSame(14, $findings[0]->callsite->line);
        self::assertStringContainsString('SELECT * FROM customers WHERE id = ?', $findings[0]->message);
    }

    /**
     * Both the SQL and the values match — that is a duplicate. Different diagnosis,
     * different rule (`duplicate-query`).
     */
    public function testIdenticalRepeatsAreNotNPlusOne(): void
    {
        $trace = $this->trace();

        foreach (range(1, 5) as $ignored) {
            $trace->record($this->event('SELECT * FROM settings WHERE id = ?', [1], '/app/src/Config.php', 7));
        }

        self::assertSame([], $this->findingsFor($trace));
    }

    /**
     * One shape but different places in the code is a coincidence, not a loop.
     */
    public function testSameShapeFromDifferentCallsitesIsNotNPlusOne(): void
    {
        $trace = $this->trace();

        foreach ([1, 2, 3, 4, 5] as $id) {
            $trace->record($this->event('SELECT * FROM users WHERE id = ?', [$id], '/app/src/File.php', $id));
        }

        self::assertSame([], $this->findingsFor($trace));
    }

    public function testBelowThresholdIsSilent(): void
    {
        $trace = $this->trace();

        foreach ([1, 2] as $id) {
            $trace->record($this->event('SELECT * FROM users WHERE id = ?', [$id], '/app/src/File.php', 3));
        }

        self::assertSame([], $this->findingsFor($trace));
    }

    public function testThresholdIsConfigurable(): void
    {
        $trace = $this->trace();

        foreach ([1, 2] as $id) {
            $trace->record($this->event('SELECT * FROM users WHERE id = ?', [$id], '/app/src/File.php', 3));
        }

        self::assertCount(1, $this->findingsFor($trace, 2));
    }

    /**
     * The fingerprint strips literals, so N+1 is visible even where the values are
     * inlined into the SQL rather than bound.
     */
    public function testInlinedLiteralsAreStillNPlusOne(): void
    {
        $trace = $this->trace();

        foreach ([1, 2, 3, 4, 5] as $id) {
            $trace->record($this->event('SELECT * FROM users WHERE id = '.$id, [], '/app/src/File.php', 3));
        }

        self::assertCount(1, $this->findingsFor($trace));
    }

    public function testTwoIndependentNPlusOnesGiveTwoFindings(): void
    {
        $trace = $this->trace();

        foreach ([1, 2, 3] as $id) {
            $trace->record($this->event('SELECT * FROM users WHERE id = ?', [$id], '/app/src/A.php', 3));
            $trace->record($this->event('SELECT * FROM tags WHERE id = ?', [$id], '/app/src/B.php', 9));
        }

        self::assertCount(2, $this->findingsFor($trace));
    }

    /**
     * Evidence of lazy loading from the adapter removes the guesswork: the association
     * is named and the severity is raised to error.
     */
    public function testLazyCollectionIsNamedAndRaisedToError(): void
    {
        $trace = $this->trace();

        foreach ([1, 2, 3, 4, 5] as $id) {
            $trace->record(new QueryEvent(
                sql: 'SELECT * FROM tags WHERE timesheet_id = ?',
                params: [$id],
                stack: [['file' => '/app/src/Report.php', 'line' => 20]],
                annotations: [
                    DoctrineEnricher::KIND => DoctrineEnricher::KIND_COLLECTION,
                    DoctrineEnricher::ENTITY => 'App\\Entity\\Timesheet',
                    DoctrineEnricher::ASSOCIATION => 'tags',
                ],
            ));
        }

        $findings = $this->findingsFor($trace);

        self::assertCount(1, $findings);
        self::assertSame(Severity::Error, $findings[0]->severity);
        self::assertSame('App\\Entity\\Timesheet::$tags — lazy-loaded association, 5 queries', $findings[0]->message);
    }

    public function testLazyEntityIsNamedWithoutAssociation(): void
    {
        $trace = $this->trace();

        foreach ([1, 2, 3] as $id) {
            $trace->record(new QueryEvent(
                sql: 'SELECT * FROM customers WHERE id = ?',
                params: [$id],
                stack: [['file' => '/app/src/Report.php', 'line' => 20]],
                annotations: [
                    DoctrineEnricher::KIND => DoctrineEnricher::KIND_ENTITY,
                    DoctrineEnricher::ENTITY => 'App\\Entity\\Customer',
                ],
            ));
        }

        self::assertSame(
            'App\\Entity\\Customer — lazy-loaded entity, 3 queries',
            $this->findingsFor($trace)[0]->message,
        );
    }

    /**
     * One lazy query among three ordinary ones of the same shape proves nothing.
     */
    public function testPartialLazyMarkingFallsBackToHeuristic(): void
    {
        $trace = $this->trace();

        $trace->record(new QueryEvent(
            sql: 'SELECT * FROM tags WHERE timesheet_id = ?',
            params: [1],
            stack: [['file' => '/app/src/Report.php', 'line' => 20]],
            annotations: [DoctrineEnricher::KIND => DoctrineEnricher::KIND_COLLECTION],
        ));
        $trace->record($this->event('SELECT * FROM tags WHERE timesheet_id = ?', [2], '/app/src/Report.php', 20));
        $trace->record($this->event('SELECT * FROM tags WHERE timesheet_id = ?', [3], '/app/src/Report.php', 20));

        $findings = $this->findingsFor($trace);

        self::assertCount(1, $findings);
        self::assertSame(Severity::Warning, $findings[0]->severity);
        self::assertStringContainsString('of the same shape', $findings[0]->message);
    }

    /**
     * A repeated batch fetch is not N+1 but a batch loader called several times (page by
     * page, say). Whole loader layers of real projects are built that way.
     */
    public function testRepeatedBatchFetchIsNotNPlusOne(): void
    {
        $trace = $this->trace();

        foreach ([[1, 2, 3], [4, 5, 6], [7, 8, 9]] as $ids) {
            $trace->record($this->event(
                'SELECT * FROM tags WHERE id IN (?, ?, ?)',
                $ids,
                '/app/src/Loader.php',
                55,
            ));
        }

        self::assertSame([], $this->findingsFor($trace));
    }

    /**
     * A regression: a static `IN` filter next to an ordinary lookup used to be read as a
     * batch fetch, and the rule skipped the query entirely. `WHERE status IN ('new',
     * 'paid') AND user_id = ?` in a loop is textbook N+1 and the most ordinary shape of
     * query there is — the false negative was in the flagship rule.
     */
    public function testAStaticInFilterDoesNotHideNPlusOne(): void
    {
        $trace = $this->trace();

        foreach (range(1, 5) as $id) {
            $trace->record($this->event(
                "SELECT * FROM orders WHERE status IN ('new', 'paid') AND user_id = ?",
                [$id],
                '/app/src/Report.php',
                31,
            ));
        }

        $findings = $this->findingsFor($trace);

        self::assertCount(1, $findings);
        self::assertSame('n-plus-one', $findings[0]->rule);
        self::assertSame(5, $findings[0]->count);
    }

    /**
     * `IN (SELECT ...)` is a subquery, not a list of keys — the comma inside it used to
     * be enough to silence the rule.
     */
    public function testASubqueryDoesNotHideNPlusOne(): void
    {
        $trace = $this->trace();

        foreach (range(1, 5) as $id) {
            $trace->record($this->event(
                'SELECT * FROM orders WHERE tag_id IN (SELECT id, kind FROM tags) AND user_id = ?',
                [$id],
                '/app/src/Report.php',
                44,
            ));
        }

        self::assertCount(1, $this->findingsFor($trace));
    }

    public function testWritesAreNotNPlusOne(): void
    {
        $trace = $this->trace();

        foreach (range(1, 50) as $id) {
            $trace->record($this->event('INSERT INTO tags (name) VALUES (?)', [$id], '/app/tests/Fixtures.php', 12));
        }

        self::assertSame([], $this->findingsFor($trace));
    }

    /**
     * @return list<Finding>
     */
    private function findingsFor(Trace $trace, ?int $threshold = null): array
    {
        $rule = new NPlusOneRule(
            new CallsiteResolver([]),
            $threshold ?? NPlusOneRule::DEFAULT_THRESHOLD,
        );

        return array_values(iterator_to_array($rule->check($trace)));
    }

    /**
     * @param list<mixed> $params
     */
    private function event(string $sql, array $params, string $file, int $line): QueryEvent
    {
        return new QueryEvent(
            sql: $sql,
            params: $params,
            stack: [['file' => $file, 'line' => $line, 'function' => 'query']],
        );
    }

    private function trace(): Trace
    {
        return new Trace(new TestIdentifier('id', 'SomeTest::testSomething'), TestOptions::none());
    }
}
