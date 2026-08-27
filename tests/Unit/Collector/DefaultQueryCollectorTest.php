<?php

declare(strict_types=1);

namespace QueryGuard\Test\Unit\Collector;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use QueryGuard\Collector\DefaultQueryCollector;
use QueryGuard\Collector\Phase;
use QueryGuard\Query\QueryEvent;
use QueryGuard\TestIdentifier;
use QueryGuard\TestOptions;

#[CoversClass(DefaultQueryCollector::class)]
final class DefaultQueryCollectorTest extends TestCase
{
    public function testQueriesBeforeTheTraceLandInTheFixtureBucket(): void
    {
        $collector = new DefaultQueryCollector();

        $collector->beginFixtures();
        $collector->record($this->event('INSERT INTO users VALUES (1)'));
        $collector->record($this->event('INSERT INTO users VALUES (2)'));

        $trace = $collector->beginTrace($this->test(), TestOptions::none());
        $collector->record($this->event('SELECT * FROM users'));

        self::assertSame(1, $trace->count());
        self::assertSame(2, $trace->fixtureQueryCount());
    }

    public function testQueriesOutsideTestsAreCountedButDropped(): void
    {
        $collector = new DefaultQueryCollector();

        $collector->record($this->event('SELECT 1'));

        self::assertSame(Phase::Idle, $collector->phase());
        self::assertSame(1, $collector->droppedOutsideTests());
        self::assertSame(1, $collector->totalRecorded());
    }

    public function testEndTraceReturnsTheTraceAndResetsState(): void
    {
        $collector = new DefaultQueryCollector();

        $collector->beginFixtures();
        $collector->beginTrace($this->test(), TestOptions::none());
        $collector->record($this->event('SELECT 1'));

        $trace = $collector->endTrace();

        self::assertNotNull($trace);
        self::assertSame(1, $trace->count());
        self::assertSame(Phase::Idle, $collector->phase());
        self::assertNull($collector->endTrace());
    }

    public function testFixtureBucketIsClearedBetweenTests(): void
    {
        $collector = new DefaultQueryCollector();

        $collector->beginFixtures();
        $collector->record($this->event('INSERT INTO users VALUES (1)'));
        $collector->beginTrace($this->test(), TestOptions::none());
        $collector->endTrace();

        $collector->beginFixtures();
        $second = $collector->beginTrace($this->test(), TestOptions::none());

        self::assertSame(0, $second->fixtureQueryCount());
    }

    private function event(string $sql): QueryEvent
    {
        return new QueryEvent($sql);
    }

    private function test(): TestIdentifier
    {
        return new TestIdentifier('id', 'SomeTest::testSomething', 'SomeTest', 'testSomething');
    }
}
