<?php

declare(strict_types=1);

namespace QueryGuard\Stand\Laravel;

/**
 * Three tests, and the number matters: the defect this stand exists for only appears from
 * the second test onwards, when the `DB` facade is still holding the previous test's
 * manager. One test would pass with the adapter broken.
 */
final class LazyLoadTest extends TestCase
{
    public function testLazyLoadingTheAuthorOfEveryBookIsCollected(): void
    {
        $titles = [];

        foreach (Book::query()->get() as $book) {
            $titles[] = $book->author->name;
        }

        self::assertCount(3, $titles);
    }

    public function testTheSameLoopInASecondTestIsCollectedToo(): void
    {
        $titles = [];

        foreach (Book::query()->get() as $book) {
            $titles[] = $book->author->name;
        }

        self::assertCount(3, $titles);
    }

    public function testAThirdTestKeepsTheApplicationBeingRebuilt(): void
    {
        $titles = [];

        foreach (Book::query()->get() as $book) {
            $titles[] = $book->author->name;
        }

        self::assertCount(3, $titles);
    }
}
