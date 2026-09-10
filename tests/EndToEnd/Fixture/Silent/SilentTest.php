<?php

declare(strict_types=1);

namespace QueryGuard\Test\EndToEnd\Fixture\Silent;

use PHPUnit\Framework\TestCase;

/**
 * Not a single query during the run — no adapter is installed. The summary has to say so
 * plainly rather than report "no findings": silent degradation is exactly how a tool
 * loses trust.
 */
final class SilentTest extends TestCase
{
    public function testWithoutAnyQueries(): void
    {
        self::assertTrue(true);
    }
}
