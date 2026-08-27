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

    public function testWhitespaceAndCaseAreNormalised(): void
    {
        self::assertTrue(
            Fingerprint::of("SELECT   *\n  FROM users;")->equals(Fingerprint::of('select * from users')),
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
