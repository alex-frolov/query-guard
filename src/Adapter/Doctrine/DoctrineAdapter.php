<?php

declare(strict_types=1);

namespace QueryGuard\Adapter\Doctrine;

use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware as MiddlewareInterface;
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
    /**
     * Every connection that has been opened, keyed by name.
     *
     * A map rather than "the last one": EXPLAIN has to go through the connection that
     * ran the query. A single static used to hold whichever connected most recently, so
     * on a project with two databases the plan of one was read against the other — and
     * parsed with the other's platform driver on top of that.
     *
     * @var array<string, array{connection: ?object, platform: string}>
     */
    private static array $connections = [];

    public static function supports(): bool
    {
        return interface_exists(MiddlewareInterface::class);
    }

    /**
     * Called from `Driver::connect()`: before the first connection there is no fact to report.
     */
    public static function markConnected(string $connectionName, string $platform = 'unknown', ?object $connection = null): void
    {
        self::$connections[$connectionName] = ['connection' => $connection, 'platform' => $platform];
    }

    public static function reset(): void
    {
        self::$connections = [];
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
        return [] !== self::$connections;
    }

    public function explainers(): array
    {
        $explainers = [];

        foreach (self::$connections as $name => $opened) {
            if ($opened['connection'] instanceof DriverConnection) {
                $explainers[$name] = new DoctrineExplainer($opened['connection'], $opened['platform']);
            }
        }

        return $explainers;
    }

    public function installationHint(): string
    {
        return 'Doctrine: add QueryGuard\Adapter\Doctrine\Middleware tagged doctrine.middleware '
            .'to your test configuration — the middleware has to be there before the connection is created.';
    }
}
