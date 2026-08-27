<?php

declare(strict_types=1);

namespace QueryGuard\Adapter\Doctrine;

use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware as MiddlewareInterface;
use QueryGuard\Adapter\Explainer;
use QueryGuard\Adapter\OrmAdapter;
use QueryGuard\Collector\QueryCollector;

/**
 * Doctrine: interception through our own DBAL middleware.
 *
 * The adapter cannot install that middleware itself, and that is not an omission: the
 * middleware has to be in the connection configuration BEFORE the connection is created,
 * and at that moment the PHPUnit extension does not exist yet. So the adapter only
 * checks whether the middleware took its place, and reports that in the summary.
 *
 * Wiring it up is one line in the application's test configuration:
 *
 *     QueryGuard\Adapter\Doctrine\Middleware:
 *         tags: ['doctrine.middleware']
 */
final class DoctrineAdapter implements OrmAdapter
{
    /** @var array<string, true> */
    private static array $connected = [];

    private static ?object $connection = null;

    private static string $platform = 'unknown';

    public static function supports(): bool
    {
        return interface_exists(MiddlewareInterface::class);
    }

    /**
     * Called from `Driver::connect()`: before the first connection there is no fact to report.
     */
    public static function markConnected(string $connectionName, string $platform = 'unknown', ?object $connection = null): void
    {
        self::$connected[$connectionName] = true;
        self::$platform = $platform;
        self::$connection = $connection;
    }

    public static function reset(): void
    {
        self::$connected = [];
        self::$connection = null;
        self::$platform = 'unknown';
    }

    public function name(): string
    {
        return 'doctrine';
    }

    public function install(QueryCollector $collector): void
    {
        // no-op: see the class docblock
    }

    public function isInstalled(): bool
    {
        return [] !== self::$connected;
    }

    public function explainer(): ?Explainer
    {
        if (!self::$connection instanceof DriverConnection) {
            return null;
        }

        return new DoctrineExplainer(self::$connection, self::$platform);
    }

    public function installationHint(): string
    {
        return 'Doctrine: add QueryGuard\Adapter\Doctrine\Middleware tagged doctrine.middleware '
            .'to your test configuration — the middleware has to be there before the connection is created.';
    }
}
