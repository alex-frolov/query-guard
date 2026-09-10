<?php

declare(strict_types=1);

namespace QueryGuard\Test\EndToEnd\Fixture\Tier1;

use PHPUnit\Framework\TestCase;
use QueryGuard\Test\EndToEnd\Fixture\Support\FakeAdapter;

final class Tier1Test extends TestCase
{
    public function testDuplicateQuery(): void
    {
        foreach ([1, 2, 3] as $ignored) {
            FakeAdapter::query('SELECT id, name FROM settings WHERE key = ?', ['locale']);
        }

        self::assertTrue(true);
    }

    public function testQueryInLoop(): void
    {
        $tables = ['orders', 'users', 'invoices', 'projects', 'activities'];

        foreach ($tables as $table) {
            FakeAdapter::query(sprintf('SELECT id FROM %s WHERE owner_id = ?', $table), [7]);
        }

        self::assertTrue(true);
    }

    public function testSelectStar(): void
    {
        FakeAdapter::query('SELECT * FROM invoices WHERE id = ?', [1]);

        self::assertTrue(true);
    }

    public function testNoLimitOnLargeTable(): void
    {
        FakeAdapter::query('SELECT id, total FROM invoices WHERE paid = ?', [1]);
        FakeAdapter::query('SELECT id FROM invoices WHERE paid = ? LIMIT 10', [1]);
        FakeAdapter::query('SELECT id FROM small_table WHERE paid = ?', [1]);

        self::assertTrue(true);
    }
}
