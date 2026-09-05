<?php

declare(strict_types=1);

namespace QueryGuard\Test\Unit\Finding;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use QueryGuard\Finding\Finding;

/**
 * `relativeTo()` used to be reimplemented, near-identically, by `Baseline`,
 * `JsonReporter` and `ConsoleReporter` — one shared home for it now.
 */
#[CoversClass(Finding::class)]
final class FindingTest extends TestCase
{
    public function testAPlainPathHasTheBasePathStrippedFromTheFront(): void
    {
        self::assertSame(
            'tests/FooTest.php',
            Finding::relativeTo('/app/tests/FooTest.php', '/app'),
        );
    }

    public function testASignatureIsShortenedOnlyOnItsMiddleField(): void
    {
        self::assertSame(
            'n-plus-one|tests/FooTest.php|select * from t where id = ?',
            Finding::relativeTo('n-plus-one|/app/tests/FooTest.php|select * from t where id = ?', '/app'),
        );
    }

    /**
     * A blanket `str_replace` would also rewrite a fingerprint that happens to contain
     * the base path as text — the middle field is the only one ever touched.
     */
    public function testAFingerprintContainingTheBasePathAsTextIsLeftAlone(): void
    {
        $signature = "n-plus-one|/app/tests/FooTest.php|select * from t where name = '/app'";

        self::assertSame(
            "n-plus-one|tests/FooTest.php|select * from t where name = '/app'",
            Finding::relativeTo($signature, '/app'),
        );
    }

    public function testAPathOutsideTheBasePathIsLeftAlone(): void
    {
        self::assertSame(
            '/elsewhere/FooTest.php',
            Finding::relativeTo('/elsewhere/FooTest.php', '/app'),
        );
    }

    public function testAnEmptyBasePathLeavesEverythingAlone(): void
    {
        self::assertSame('/app/tests/FooTest.php', Finding::relativeTo('/app/tests/FooTest.php', ''));
    }
}
