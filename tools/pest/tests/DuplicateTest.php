<?php

declare(strict_types=1);

namespace QueryGuard\Stand\Pest;

use PHPUnit\Framework\TestCase;
use QueryGuard\Query\QueryEvent;
use QueryGuard\QueryGuard;

final class DuplicateTest extends TestCase
{
    public function testTheSameQueryThreeTimes(): void
    {
        foreach ([1, 2, 3] as $ignored) {
            QueryGuard::collector()->record(new QueryEvent('SELECT id, name FROM settings WHERE key = ?', ['locale']));
        }

        self::assertTrue(true);
    }
}
