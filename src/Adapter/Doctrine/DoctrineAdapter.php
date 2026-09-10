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
     * Every connection that has been opened, keyed by the name it is known under.
     *
     * A map rather than "the last one": EXPLAIN has to go through the connection that
     * ran the query. A single static used to hold whichever connected most recently, so
     * on a project with two databases the plan of one was read against the other — and
     * parsed with the other's platform driver on top of that.
     *
     * @var array<string, array{connection: ?object, platform: string}>
     */
    private static array $connections = [];

    /**
     * Endpoint → the name it was given. See `register()`.
     *
     * @var array<string, string>
     */
    private static array $names = [];

    /**
     * Names that had to be disambiguated, and the endpoints behind them.
     *
     * @var array<string, string>
     */
    private static array $collisions = [];

    public static function supports(): bool
    {
        return interface_exists(MiddlewareInterface::class);
    }

    /**
     * Records an opened connection and answers with the name it is known under.
     *
     * Called from `Driver::connect()`: before the first connection there is no fact to
     * report.
     *
     * **The label and the endpoint are two different things, and conflating them lost
     * connections.** The label is what a developer recognises — the database name — and
     * it is what the summary prints. The endpoint is the whole of `dbname`, host, port
     * and user, which is what actually identifies a database. A primary and a replica
     * carry one label and two endpoints; keying by the label alone meant the second
     * overwrote the first, and tier 2 then explained the replica's queries against the
     * primary. Keying by the endpoint alone would print `shop|10.0.0.4|3306` in every
     * notice for the overwhelmingly common project that has exactly one database.
     *
     * So: the label is used as the name while it is free, a second endpoint claiming it
     * becomes `shop#2`, and the fact that this happened reaches the summary. Reconnecting
     * the same endpoint keeps the name it already had — DBAL reconnects, and a run must
     * not accumulate a new connection per reconnect.
     */
    public static function register(string $label, string $endpoint, string $platform = 'unknown', ?object $connection = null): string
    {
        $entry = ['connection' => $connection, 'platform' => $platform];

        if (isset(self::$names[$endpoint])) {
            $name = self::$names[$endpoint];
            self::$connections[$name] = $entry;

            return $name;
        }

        $name = '' === $label ? 'default' : $label;

        for ($suffix = 2; isset(self::$connections[$name]); ++$suffix) {
            $name = $label.'#'.$suffix;
            self::$collisions[$name] = $label;
        }

        self::$names[$endpoint] = $name;
        self::$connections[$name] = $entry;

        return $name;
    }

    public static function reset(): void
    {
        self::$connections = [];
        self::$names = [];
        self::$collisions = [];
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

    public function notices(): array
    {
        $notices = [];

        foreach (self::$collisions as $name => $label) {
            $notices[] = sprintf(
                "two connections answer to the database name \"%s\", so the second one is reported as \"%s\".\n"
                .'They are separate databases — different host, port or user — and each is explained against itself.',
                $label,
                $name,
            );
        }

        return $notices;
    }
}
