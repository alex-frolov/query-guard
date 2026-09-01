<?php

declare(strict_types=1);

namespace QueryGuard\Test\Unit\Query;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use QueryGuard\Query\Callsite;
use QueryGuard\Query\CallsiteResolver;
use QueryGuard\Query\QueryEvent;

/**
 * The event answers three questions the rules cannot double-check: is this a read, what
 * exact form did it take, and where did it come from. A wrong answer here is invisible —
 * the query simply stops being considered, with nothing said in the summary.
 */
#[CoversClass(QueryEvent::class)]
final class QueryEventTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function selectProvider(): iterable
    {
        yield 'plain select' => ['SELECT * FROM t', true];
        yield 'leading whitespace' => ["\n  SELECT 1", true];
        yield 'common table expression' => ['WITH x AS (SELECT 1) SELECT * FROM x', true];
        yield 'block comment' => ['/* app: report */ SELECT * FROM t', true];

        // sqlcommenter-style tracing emits these. Read as a write, such a query drops out
        // of `n-plus-one`, out of `duplicate-query` and out of tier 2 without a word
        yield 'line comment' => ["-- app: report\nSELECT * FROM t", true];
        yield 'both comment spellings' => ["/* a */ -- b\n WITH x AS (SELECT 1) SELECT * FROM x", true];

        yield 'insert' => ['INSERT INTO t VALUES (1)', false];
        yield 'update' => ['UPDATE t SET a = 1', false];
        // the keyword is inside the comment, not the statement
        yield 'a select named in a line comment above a write' => ["-- SELECT\nINSERT INTO t VALUES (1)", false];
    }

    #[DataProvider('selectProvider')]
    public function testReadsAreToldApartFromWritesPastAnyComment(string $sql, bool $expected): void
    {
        self::assertSame($expected, (new QueryEvent($sql))->isSelect());
    }

    public function testShapeSeparatesQueriesByTheirBoundValues(): void
    {
        $first = new QueryEvent('SELECT * FROM t WHERE id = ?', [1]);
        $second = new QueryEvent('SELECT * FROM t WHERE id = ?', [2]);
        $same = new QueryEvent('SELECT * FROM t WHERE id = ?', [1]);

        self::assertNotSame($first->shape(), $second->shape());
        self::assertSame($first->shape(), $same->shape());
    }

    /**
     * `serialize()` throws on a closure. This runs inside `Test\Finished`, so the
     * exception would escape into PHPUnit's event dispatcher: a tool that watches for
     * problems must not be able to break the suite it is watching.
     */
    public function testShapeSurvivesAParameterThatCannotBeSerialised(): void
    {
        $event = new QueryEvent('SELECT * FROM t WHERE id = ?', [static fn (): int => 1]);

        self::assertStringStartsWith('SELECT * FROM t WHERE id = ?|', $event->shape());
    }

    public function testCallsiteIsResolvedFromTheStackAndMemoised(): void
    {
        $event = new QueryEvent('SELECT 1', stack: [
            ['file' => '/app/vendor/phpunit/x.php', 'line' => 1],
            ['file' => '/app/src/Report.php', 'line' => 42, 'function' => 'render'],
        ]);

        $resolver = new CallsiteResolver(['#/vendor/#']);
        $callsite = $event->callsite($resolver);

        self::assertNotNull($callsite);
        self::assertSame('/app/src/Report.php', $callsite->file);
        self::assertSame(42, $callsite->line);
        self::assertSame($callsite, $event->callsite($resolver));
    }

    /**
     * The memo used to be keyed by `spl_object_id()`, and PHP hands a freed object's id
     * to the next one allocated. A resolver created after another was collected would
     * then inherit its answer — and report a callsite it had been told to skip.
     */
    public function testAResolverDoesNotInheritAnotherResolversMemo(): void
    {
        $event = new QueryEvent('SELECT 1', stack: [
            ['file' => '/app/src/Report.php', 'line' => 42, 'function' => 'render'],
        ]);

        $permissive = new CallsiteResolver([]);
        self::assertNotNull($event->callsite($permissive));

        unset($permissive);

        // skips everything the stack has to offer, so the only correct answer is null
        self::assertNull($event->callsite(new CallsiteResolver(['#/app/src/#'])));
    }

    public function testAnAdapterResolvedCallsiteWinsOverTheStack(): void
    {
        $event = new QueryEvent('SELECT 1', stack: [
            ['file' => '/app/src/FromStack.php', 'line' => 1],
        ], callsite: new Callsite('/app/src/FromAdapter.php', 7));

        $callsite = $event->callsite(new CallsiteResolver([]));

        self::assertNotNull($callsite);
        self::assertSame('/app/src/FromAdapter.php', $callsite->file);
    }
}
