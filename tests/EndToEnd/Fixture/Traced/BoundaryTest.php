<?php

declare(strict_types=1);

namespace QueryGuard\Test\EndToEnd\Fixture\Traced;

use PHPUnit\Framework\TestCase;
use QueryGuard\Test\EndToEnd\Fixture\Support\FakeAdapter;

/**
 * Where the trace boundary falls relative to setUp(). This test is the answer, recorded
 * in a way that cannot be broken silently.
 */
final class BoundaryTest extends TestCase
{
    protected function setUp(): void
    {
        FakeAdapter::query('INSERT INTO users (name) VALUES (?)', ['fixture 1']);
        FakeAdapter::query('INSERT INTO users (name) VALUES (?)', ['fixture 2']);
        FakeAdapter::query('INSERT INTO users (name) VALUES (?)', ['fixture 3']);
    }

    public function testBodyQueriesLandInTheTrace(): void
    {
        FakeAdapter::query('SELECT * FROM users');
        FakeAdapter::query('SELECT * FROM orders WHERE user_id = ?', [1]);

        self::assertTrue(true);
    }
}
