<?php

declare(strict_types=1);

namespace QueryGuard\Test\EndToEnd\Fixture\Traced;

use PHPUnit\Framework\TestCase;
use QueryGuard\Attribute\AllowQueries;
use QueryGuard\Attribute\IgnoreRule;
use QueryGuard\Test\EndToEnd\Fixture\Support\FakeAdapter;

final class ThresholdTest extends TestCase
{
    public function testOverTheThreshold(): void
    {
        $this->runQueries(5);

        self::assertTrue(true);
    }

    #[AllowQueries(10)]
    public function testAllowQueriesRaisesTheThreshold(): void
    {
        $this->runQueries(5);

        self::assertTrue(true);
    }

    #[IgnoreRule('query-count')]
    #[IgnoreRule('n-plus-one')]
    public function testIgnoreRuleSilencesTheRule(): void
    {
        $this->runQueries(5);

        self::assertTrue(true);
    }

    /**
     * Queries against different tables: this class is about the query budget, and there
     * is no reason to mix an `n-plus-one` hit into it.
     */
    private function runQueries(int $amount): void
    {
        $tables = ['orders', 'users', 'invoices', 'projects', 'activities', 'tags'];

        for ($i = 0; $i < $amount; $i++) {
            FakeAdapter::query(sprintf('SELECT * FROM %s WHERE id = ?', $tables[$i]), [$i]);
        }
    }
}
