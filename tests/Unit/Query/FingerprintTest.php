<?php

declare(strict_types=1);

namespace QueryGuard\Test\Unit\Query;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use QueryGuard\Query\Fingerprint;

#[CoversClass(Fingerprint::class)]
final class FingerprintTest extends TestCase
{
    /**
     * The whole point of a fingerprint: in N+1 the bound values differ while the shape
     * is one. A competitor compares SQL together with the values and therefore never
     * sees N+1.
     */
    public function testSameShapeWithDifferentBoundValuesGivesSameFingerprint(): void
    {
        $first = Fingerprint::of('SELECT * FROM activities WHERE id = 2');
        $second = Fingerprint::of('SELECT * FROM activities WHERE id = 137');

        self::assertTrue($first->equals($second));
    }

    public function testDifferentTablesGiveDifferentFingerprints(): void
    {
        self::assertFalse(
            Fingerprint::of('SELECT * FROM users')->equals(Fingerprint::of('SELECT * FROM orders')),
        );
    }

    public function testStringLiteralsAreReplaced(): void
    {
        self::assertSame(
            'select * from users where name = ?',
            Fingerprint::of("SELECT * FROM users WHERE name = 'O''Brien'")->value(),
        );
    }

    public function testInListsAreCollapsed(): void
    {
        self::assertSame(
            Fingerprint::of('SELECT * FROM t WHERE id IN (?, ?, ?)')->value(),
            Fingerprint::of('SELECT * FROM t WHERE id IN (?, ?, ?, ?, ?, ?)')->value(),
        );
    }

    /**
     * One statement, batches of different size. `IN (?, ?)` was collapsed long ago;
     * `VALUES` was not, and a batch writer looked like several different queries.
     */
    public function testMultiRowValuesAreCollapsed(): void
    {
        $one = Fingerprint::of('INSERT INTO tag (name, slug) VALUES (?, ?)');
        $three = Fingerprint::of('INSERT INTO tag (name, slug) VALUES (?, ?), (?, ?), (?, ?)');
        $literals = Fingerprint::of("INSERT INTO tag (name, slug) VALUES ('a', 'b'),('c', 'd')");

        self::assertSame('insert into tag (name, slug) values (?, ?)', $one->value());
        self::assertTrue($one->equals($three));
        self::assertTrue($one->equals($literals));
    }

    public function testMultiRowValuesWithAFunctionCallAreCollapsed(): void
    {
        self::assertSame(
            'insert ignore into log (id, at) values (?, now()) on duplicate key update at = now()',
            Fingerprint::of('INSERT IGNORE INTO log (id, at) VALUES (1, NOW()), (2, NOW()) ON DUPLICATE KEY UPDATE at = NOW()')->value(),
        );
    }

    /**
     * Only a list of tuples is a batch. A single row stays exactly as it was, and so does
     * its fingerprint — and with it every baseline entry written for it.
     */
    public function testASingleRowIsLeftAlone(): void
    {
        self::assertSame(
            'insert into tag (name) values (?)',
            Fingerprint::of('INSERT INTO tag (name) VALUES (?)')->value(),
        );
    }

    public function testWhitespaceAndCaseAreNormalised(): void
    {
        self::assertTrue(
            Fingerprint::of("SELECT   *\n  FROM users;")->equals(Fingerprint::of('select * from users')),
        );
    }

    /**
     * The quietest failure this package had: tracing middleware appends a per-request
     * comment to every statement, so the same query arrived with a different fingerprint
     * each time and both grouping rules saw a suite of unique queries.
     */
    public function testAPerRequestCommentDoesNotSplitAShape(): void
    {
        $first = Fingerprint::of('SELECT * FROM users WHERE id = ? /* trace=abc123 */');
        $second = Fingerprint::of('SELECT * FROM users WHERE id = ? /* trace=def456 */');

        self::assertTrue($first->equals($second));
        self::assertTrue($first->equals(Fingerprint::of('SELECT * FROM users WHERE id = ?')));
    }

    public function testALineCommentIsNotPartOfTheShape(): void
    {
        self::assertTrue(
            Fingerprint::of("-- request 17\nSELECT * FROM users")
                ->equals(Fingerprint::of("-- request 18\nSELECT * FROM users")),
        );
    }

    /**
     * The same trap `Rule\Sql` guards: stripping comments before literals lets the `--`
     * inside a string eat its closing quote, and everything after it stops being SQL.
     */
    public function testACommentMarkerInsideALiteralIsStillALiteral(): void
    {
        self::assertSame(
            'select * from notes where body = ? and id = ?',
            Fingerprint::of("SELECT * FROM notes WHERE body = 'a -- b' AND id = 7")->value(),
        );
    }

    /**
     * MySQL honours backslash escapes by default (without NO_BACKSLASH_ESCAPES) — a
     * second, equally valid spelling of an escaped quote next to the SQL-standard `''`.
     */
    public function testABackslashEscapedQuoteDoesNotEndTheLiteralEarly(): void
    {
        self::assertSame(
            'select * from notes where body = ? and id = ?',
            Fingerprint::of("SELECT * FROM notes WHERE body = 'a \\'-- b' AND id = 7")->value(),
        );
    }

    /**
     * Doctrine's aliases (`id_1`, `name_2`) are not literals: stripping them would
     * collapse different queries into one fingerprint.
     */
    public function testDoctrineColumnAliasesSurvive(): void
    {
        self::assertSame(
            'select t0.id as id_1, t0.name as name_2 from users t0',
            Fingerprint::of('SELECT t0.id AS id_1, t0.name AS name_2 FROM users t0')->value(),
        );
    }
}
