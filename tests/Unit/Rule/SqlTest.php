<?php

declare(strict_types=1);

namespace QueryGuard\Test\Unit\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use QueryGuard\Rule\Sql;

/**
 * These helpers decide what the rules do and do not look at, so a mistake here is
 * invisible: `isBatchFetch()` returning true wrongly makes the `n-plus-one` rule skip a
 * query without a word.
 */
#[CoversClass(Sql::class)]
final class SqlTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function batchFetchProvider(): iterable
    {
        yield 'placeholders' => ['SELECT * FROM t WHERE id IN (?, ?, ?)', true];
        yield 'named placeholders' => ['SELECT * FROM t WHERE id IN (:a, :b)', true];
        yield 'postgres placeholders' => ['SELECT * FROM t WHERE id IN ($1, $2)', true];
        yield 'inlined numbers' => ['SELECT * FROM t WHERE id IN (10, 20, 30)', true];

        // a filter by a fixed set of statuses says nothing about how many rows come back;
        // repeated in a loop this is N+1, and it used to be read as a batch fetch
        yield 'a static list of statuses' => ["SELECT * FROM t WHERE status IN ('new', 'paid') AND user_id = ?", false];
        yield 'a subquery' => ['SELECT * FROM t WHERE id IN (SELECT a, b FROM u)', false];
        yield 'a list inside a comment' => ['SELECT * FROM t WHERE id = ? /* IN (a, b) */', false];
        yield 'a single key' => ['SELECT * FROM t WHERE id IN (?)', false];
        yield 'no list at all' => ['SELECT * FROM t WHERE id = ?', false];

        // a literal is not a comment. Stripping comments before literals used to let the
        // `--` inside this string eat the closing quote, and everything after it stopped
        // being recognisable — the batch fetch went unseen and `n-plus-one` fired on the
        // very query that cures N+1
        yield 'a line-comment marker inside a literal' => ["SELECT * FROM notes WHERE body = 'a -- b' AND id IN (1, 2, 3)", true];
        yield 'a block-comment marker inside a literal' => ["SELECT * FROM t WHERE pattern = '/*' AND id IN (?, ?)", true];
        yield 'an escaped quote around a marker' => ["SELECT * FROM t WHERE q = 'it''s -- fine' AND id IN (?, ?)", true];

        // and the converse still holds: a real comment hides a real list
        yield 'a list inside a line comment' => ["SELECT * FROM t WHERE id = ?\n-- id IN (1, 2, 3)", false];
    }

    #[DataProvider('batchFetchProvider')]
    public function testBatchFetchIsAListOfKeysAndNothingElse(string $sql, bool $expected): void
    {
        self::assertSame($expected, Sql::isBatchFetch($sql));
    }

    public function testLimitIsNotFoundInsideALiteralOrAComment(): void
    {
        self::assertTrue(Sql::hasLimit('SELECT * FROM users LIMIT 10'));
        self::assertFalse(Sql::hasLimit("SELECT * FROM users WHERE name = 'LIMIT'"));
        self::assertFalse(Sql::hasLimit('SELECT * FROM users -- LIMIT 10'));
        self::assertFalse(Sql::hasLimit('SELECT * FROM users /* LIMIT 10 */'));

        // the literal holds a `--`, which must not swallow the rest of the statement
        self::assertTrue(Sql::hasLimit("SELECT * FROM notes WHERE body = 'a -- b' LIMIT 10"));
    }

    public function testTableIsNotFoundInsideALiteral(): void
    {
        self::assertTrue(Sql::touchesTable('SELECT * FROM orders o JOIN users u ON 1', 'users'));
        self::assertFalse(Sql::touchesTable("SELECT * FROM orders WHERE note = 'FROM users'", 'users'));
        // a prefix must not count: `users` is not `users_archive`
        self::assertFalse(Sql::touchesTable('SELECT * FROM users_archive', 'users'));
    }

    public function testTableIsFoundThroughItsQualifiers(): void
    {
        self::assertTrue(Sql::touchesTable('SELECT * FROM public.users', 'users'));
        self::assertTrue(Sql::touchesTable('SELECT * FROM "public"."users"', 'users'));
        self::assertTrue(Sql::touchesTable('SELECT * FROM `shop`.`users`', 'users'));
        self::assertTrue(Sql::touchesTable('SELECT * FROM [dbo].[users]', 'users'));
        self::assertTrue(Sql::touchesTable('SELECT * FROM shop.public.users', 'users'));
        self::assertTrue(Sql::touchesTable('SELECT * FROM orders o JOIN public.users u ON 1', 'users'));
    }

    /**
     * The schema in the configured name is a filter, not decoration.
     */
    public function testAQualifiedConfiguredNameKeepsItsSchema(): void
    {
        self::assertTrue(Sql::touchesTable('SELECT * FROM public.users', 'public.users'));
        self::assertTrue(Sql::touchesTable('SELECT * FROM "public"."users"', 'public.users'));
        self::assertTrue(Sql::touchesTable('SELECT * FROM public.users', '"public"."users"'));
        self::assertFalse(Sql::touchesTable('SELECT * FROM archive.users', 'public.users'));
        self::assertFalse(Sql::touchesTable('SELECT * FROM users', 'public.users'));
    }

    /**
     * A qualifier is a schema or a database, not the table being read.
     */
    public function testAQualifierIsNotTheTable(): void
    {
        self::assertFalse(Sql::touchesTable('SELECT * FROM shop.users', 'shop'));
        self::assertFalse(Sql::touchesTable('SELECT * FROM "shop"."users"', 'shop'));
        self::assertFalse(Sql::touchesTable('SELECT * FROM shop.public.users', 'shop.public'));
    }

    public function testSelectStarLooksPastALeadingComment(): void
    {
        self::assertTrue(Sql::isSelectStar('SELECT * FROM users'));
        self::assertTrue(Sql::isSelectStar('SELECT t0.* FROM users t0'));
        self::assertTrue(Sql::isSelectStar('/* app: report */ SELECT * FROM users'));
        self::assertFalse(Sql::isSelectStar('SELECT id, name FROM users'));
    }

    public function testShortenCollapsesWhitespaceAndTruncates(): void
    {
        self::assertSame('SELECT a FROM b', Sql::shorten("SELECT   a\n  FROM b "));
        self::assertSame(20, mb_strlen(Sql::shorten(str_repeat('x', 100), 20)));
        self::assertStringEndsWith('...', Sql::shorten(str_repeat('x', 100), 20));
    }
}
