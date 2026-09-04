<?php

declare(strict_types=1);

namespace QueryGuard\Test\Unit\Query;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use QueryGuard\Query\QueryEvent;
use QueryGuard\Query\Trace;
use QueryGuard\TestIdentifier;
use QueryGuard\TestOptions;

#[CoversClass(Trace::class)]
final class TraceTest extends TestCase
{
    public function testGroupsQueriesByFingerprintIgnoringBoundValues(): void
    {
        $trace = $this->trace();
        $trace->record(new QueryEvent('SELECT * FROM activities WHERE id = ?', [1]));
        $trace->record(new QueryEvent('SELECT * FROM activities WHERE id = ?', [2]));
        $trace->record(new QueryEvent('SELECT * FROM users'));

        $groups = $trace->groupedByFingerprint();

        self::assertCount(2, $groups);
        self::assertCount(2, $groups['select * from activities where id = ?']);
    }

    public function testFixtureEventsAreKeptApartFromTheTrace(): void
    {
        $trace = new Trace(
            $this->test(),
            TestOptions::none(),
            [new QueryEvent('INSERT INTO users VALUES (1)')],
        );
        $trace->record(new QueryEvent('SELECT * FROM users'));

        self::assertSame(1, $trace->count());
        self::assertSame(1, $trace->fixtureQueryCount());
        self::assertCount(1, $trace->fixtureEvents());
    }

    public function testAFreshTraceHasNoEvents(): void
    {
        self::assertSame(0, $this->trace()->count());
        self::assertSame([], $this->trace()->events());
    }

    private function trace(): Trace
    {
        return new Trace($this->test(), TestOptions::none());
    }

    private function test(): TestIdentifier
    {
        return new TestIdentifier('id', 'SomeTest::testSomething');
    }
}
