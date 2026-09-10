<?php

declare(strict_types=1);

namespace QueryGuard\Test\EndToEnd\Fixture\Support;

use QueryGuard\Query\QueryEvent;
use QueryGuard\QueryGuard;

/**
 * Stands in for a real ORM adapter: writes events into the same seam a DBAL middleware
 * would. These fixtures exercise the framework, not Doctrine.
 */
final class FakeAdapter
{
    /**
     * @param list<mixed> $params
     */
    public static function query(string $sql, array $params = [], float $durationMs = 0.1): void
    {
        QueryGuard::collector()->record(new QueryEvent(
            sql: $sql,
            params: $params,
            durationMs: $durationMs,
            connection: 'default',
            stack: debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS),
        ));
    }
}
