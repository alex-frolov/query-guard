<?php

declare(strict_types=1);

namespace QueryGuard\Test\EndToEnd\Fixture\Traced;

use PHPUnit\Framework\TestCase;
use QueryGuard\Test\EndToEnd\Fixture\Support\FakeAdapter;

final class NPlusOneTest extends TestCase
{
    /**
     * Textbook N+1: a list query plus one fetch per row, all from the same line.
     */
    public function testClassicNPlusOne(): void
    {
        FakeAdapter::query('SELECT * FROM orders');

        foreach ([1, 2, 3, 4, 5] as $id) {
            FakeAdapter::query('SELECT * FROM customers WHERE id = ?', [$id]);
        }

        self::assertTrue(true);
    }

    /**
     * The same queries, but the values match throughout — a duplicate, not N+1.
     * Different diagnosis and different fix, hence different rules.
     */
    public function testIdenticalRepeatsAreNotNPlusOne(): void
    {
        foreach ([1, 2, 3, 4, 5] as $ignored) {
            FakeAdapter::query('SELECT * FROM settings WHERE id = ?', [1]);
        }

        self::assertTrue(true);
    }
}
