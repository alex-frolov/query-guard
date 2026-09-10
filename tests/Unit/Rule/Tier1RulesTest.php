<?php

declare(strict_types=1);

namespace QueryGuard\Test\Unit\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use QueryGuard\Finding\Finding;
use QueryGuard\Finding\Severity;
use QueryGuard\Query\CallsiteResolver;
use QueryGuard\Query\QueryEvent;
use QueryGuard\Query\Trace;
use QueryGuard\Rule\DuplicateQueryRule;
use QueryGuard\Rule\NoLimitRule;
use QueryGuard\Rule\QueryInLoopRule;
use QueryGuard\Rule\Rule;
use QueryGuard\Rule\SelectStarRule;
use QueryGuard\TestIdentifier;
use QueryGuard\TestOptions;

#[CoversClass(DuplicateQueryRule::class)]
#[CoversClass(QueryInLoopRule::class)]
#[CoversClass(SelectStarRule::class)]
#[CoversClass(NoLimitRule::class)]
final class Tier1RulesTest extends TestCase
{
    public function testDuplicateQueryNeedsIdenticalValues(): void
    {
        $trace = $this->trace();

        foreach ([1, 1, 1] as $id) {
            $trace->record($this->event('SELECT * FROM settings WHERE id = ?', [$id], '/project/src/A.php', 5));
        }

        $findings = $this->findingsFor(new DuplicateQueryRule($this->resolver(), 3), $trace);

        self::assertCount(1, $findings);
        self::assertSame(3, $findings[0]->count);
    }

    /**
     * Differing values mean N+1, and its own rule reports that.
     */
    public function testDifferentValuesAreNotDuplicates(): void
    {
        $trace = $this->trace();

        foreach ([1, 2, 3] as $id) {
            $trace->record($this->event('SELECT * FROM settings WHERE id = ?', [$id], '/project/src/A.php', 5));
        }

        self::assertSame([], $this->findingsFor(new DuplicateQueryRule($this->resolver(), 3), $trace));
    }

    public function testRepeatedWritesAreNotDuplicates(): void
    {
        $trace = $this->trace();

        foreach ([1, 1, 1] as $ignored) {
            $trace->record($this->event('INSERT INTO log (message) VALUES (?)', ['x'], '/project/src/A.php', 5));
        }

        self::assertSame([], $this->findingsFor(new DuplicateQueryRule($this->resolver(), 3), $trace));
    }

    public function testQueryInLoopNeedsSeveralShapes(): void
    {
        $trace = $this->trace();

        foreach (['orders', 'users', 'invoices', 'projects', 'tags'] as $table) {
            $trace->record($this->event("SELECT id FROM {$table}", [], '/project/src/Loop.php', 12));
        }

        $findings = $this->findingsFor(new QueryInLoopRule($this->resolver()), $trace);

        self::assertCount(1, $findings);
        self::assertStringContainsString('5 queries of 5 different shapes', $findings[0]->message);
    }

    /**
     * One shape from one place is either N+1 or a duplicate; nothing should be reported twice.
     */
    public function testSingleShapeIsLeftToOtherRules(): void
    {
        $trace = $this->trace();

        foreach (range(1, 6) as $id) {
            $trace->record($this->event('SELECT id FROM orders WHERE id = ?', [$id], '/project/src/Loop.php', 12));
        }

        self::assertSame([], $this->findingsFor(new QueryInLoopRule($this->resolver()), $trace));
    }

    /**
     * `select *` is Eloquent's default mode, so the rule stays off until switched on
     * explicitly.
     */
    public function testSelectStarIsOffByDefault(): void
    {
        $trace = $this->trace();
        $trace->record($this->event('SELECT * FROM users', [], '/project/src/A.php', 5));

        self::assertSame([], $this->findingsFor(new SelectStarRule($this->resolver()), $trace));
        self::assertCount(1, $this->findingsFor(new SelectStarRule($this->resolver(), true), $trace));
    }

    public function testSelectStarSeverityIsInfo(): void
    {
        $trace = $this->trace();
        $trace->record($this->event('SELECT t0.* FROM users t0', [], '/project/src/A.php', 5));

        self::assertSame(Severity::Info, $this->findingsFor(new SelectStarRule($this->resolver(), true), $trace)[0]->severity);
    }

    public function testNoLimitIsSilentWithoutConfiguredTables(): void
    {
        $trace = $this->trace();
        $trace->record($this->event('SELECT id FROM invoices', [], '/project/src/A.php', 5));

        self::assertSame([], $this->findingsFor(new NoLimitRule($this->resolver()), $trace));
    }

    public function testNoLimitFiresOnlyForListedTablesWithoutLimit(): void
    {
        $trace = $this->trace();
        $trace->record($this->event('SELECT id FROM invoices', [], '/project/src/A.php', 5));
        $trace->record($this->event('SELECT id FROM invoices LIMIT 10', [], '/project/src/A.php', 6));
        $trace->record($this->event('SELECT id FROM tiny', [], '/project/src/A.php', 7));

        $findings = $this->findingsFor(new NoLimitRule($this->resolver(), ['invoices']), $trace);

        self::assertCount(1, $findings);
        self::assertStringContainsString('"invoices"', $findings[0]->message);
    }

    /**
     * `large-tables` holds names, not spellings: an ORM qualifies by schema whenever one
     * is configured, and the rule used to go quiet on exactly those projects.
     */
    public function testNoLimitSeesASchemaQualifiedTable(): void
    {
        $trace = $this->trace();
        $trace->record($this->event('SELECT id FROM public.invoices', [], '/project/src/A.php', 5));
        $trace->record($this->event('SELECT id FROM "public"."invoices" LIMIT 10', [], '/project/src/A.php', 6));

        $findings = $this->findingsFor(new NoLimitRule($this->resolver(), ['invoices']), $trace);

        self::assertCount(1, $findings);
        self::assertStringContainsString('"invoices"', $findings[0]->message);
    }

    public function testEveryFindingCarriesASignature(): void
    {
        $trace = $this->trace();

        foreach ([1, 1, 1] as $id) {
            $trace->record($this->event('SELECT * FROM settings WHERE id = ?', [$id], '/project/src/A.php', 5));
        }

        $finding = $this->findingsFor(new DuplicateQueryRule($this->resolver(), 3), $trace)[0];

        self::assertSame(
            'duplicate-query|/project/src/A.php|select * from settings where id = ?',
            $finding->signature,
        );
    }

    /**
     * @return list<Finding>
     */
    private function findingsFor(Rule $rule, Trace $trace): array
    {
        return array_values(iterator_to_array($rule->check($trace)));
    }

    private function resolver(): CallsiteResolver
    {
        return new CallsiteResolver([]);
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
