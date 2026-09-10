<?php

declare(strict_types=1);

namespace QueryGuard\Test\EndToEnd\Fixture\StrictFailing;

use PHPUnit\Framework\TestCase;
use QueryGuard\Test\EndToEnd\Fixture\Support\FakeAdapter;

/**
 * Both things go wrong at once: the query budget is blown AND the test errors.
 *
 * An error rather than a failed assertion on purpose — PHPUnit answers 2 for errors and
 * 1 for failures, and only the more specific code proves that query-guard left it alone.
 */
final class StrictFailingTest extends TestCase
{
    public function testErrorsAfterGoingOverTheBudget(): void
    {
        foreach (['orders', 'users', 'invoices', 'projects', 'activities'] as $index => $table) {
            FakeAdapter::query(sprintf('SELECT * FROM %s WHERE id = ?', $table), [$index]);
        }

        throw new \RuntimeException('the test itself blew up');
    }
}
