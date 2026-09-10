<?php

declare(strict_types=1);

namespace QueryGuard\Stand\Pest;

use PHPUnit\Framework\TestCase;
use QueryGuard\Query\QueryEvent;
use QueryGuard\QueryGuard;

/**
 * A second test class so that `--parallel` has something to spread over two workers.
 */
final class LoopTest extends TestCase
{
    public function testAQueryPerTable(): void
    {
        foreach (['orders', 'users', 'invoices', 'projects', 'activities'] as $table) {
            QueryGuard::collector()->record(new QueryEvent(sprintf('SELECT id FROM %s WHERE owner_id = ?', $table), [7]));
        }

        self::assertTrue(true);
    }
}
