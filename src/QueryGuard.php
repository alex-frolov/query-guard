<?php

declare(strict_types=1);

namespace QueryGuard;

use QueryGuard\Collector\NullQueryCollector;
use QueryGuard\Collector\QueryCollector;

/**
 * The point through which adapters find the collector.
 *
 * The static state here is not laziness: a DBAL middleware is built by the application's
 * container while the connection is being configured, and no amount of dependency
 * injection can reach the PHPUnit extension from there. Until the extension activates,
 * a null object is returned — so an adapter in an ordinary, non-test run neither fails
 * nor costs anything.
 */
final class QueryGuard
{
    private static ?QueryCollector $collector = null;

    private static ?QueryCollector $null = null;

    public static function collector(): QueryCollector
    {
        return self::$collector ?? self::$null ??= new NullQueryCollector();
    }

    public static function activate(QueryCollector $collector): void
    {
        self::$collector = $collector;
    }

    public static function deactivate(): void
    {
        self::$collector = null;
    }

    public static function isActive(): bool
    {
        return null !== self::$collector;
    }
}
