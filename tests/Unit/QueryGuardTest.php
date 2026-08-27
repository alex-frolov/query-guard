<?php

declare(strict_types=1);

namespace QueryGuard\Test\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use QueryGuard\Collector\DefaultQueryCollector;
use QueryGuard\Collector\NullQueryCollector;
use QueryGuard\Query\QueryEvent;
use QueryGuard\QueryGuard;

#[CoversClass(QueryGuard::class)]
final class QueryGuardTest extends TestCase
{
    protected function tearDown(): void
    {
        QueryGuard::deactivate();
    }

    /**
     * An adapter lives in the application's configuration and is always active, while
     * the extension only exists under PHPUnit. Without the null object an ordinary run
     * would fail on its first query.
     */
    public function testWithoutTheExtensionTheCollectorIsAHarmlessStub(): void
    {
        self::assertFalse(QueryGuard::isActive());
        self::assertInstanceOf(NullQueryCollector::class, QueryGuard::collector());

        QueryGuard::collector()->record(new QueryEvent('SELECT 1'));
    }

    public function testActivateReplacesTheCollector(): void
    {
        $collector = new DefaultQueryCollector();
        QueryGuard::activate($collector);

        self::assertTrue(QueryGuard::isActive());
        self::assertSame($collector, QueryGuard::collector());

        QueryGuard::deactivate();

        self::assertFalse(QueryGuard::isActive());
        self::assertInstanceOf(NullQueryCollector::class, QueryGuard::collector());
    }
}
