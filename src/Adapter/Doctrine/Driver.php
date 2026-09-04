<?php

declare(strict_types=1);

namespace QueryGuard\Adapter\Doctrine;

use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/**
 * Returns a wrapped connection and, along the way, tells the adapter that interception
 * actually happened: until the first connection a middleware proves nothing.
 */
final class Driver extends AbstractDriverMiddleware
{
    public function __construct(
        DriverInterface $driver,
        private readonly Recorder $recorder,
    ) {
        parent::__construct($driver);
    }

    /**
     * @param array<string, mixed> $params
     */
    public function connect(
        #[\SensitiveParameter]
        array $params,
    ): DriverConnection {
        /** @phpstan-ignore argument.type (DBAL describes connection parameters with a
         *  strict array shape; a decorator is fine with whatever it was handed) */
        $wrapped = parent::connect($params);

        $connectionName = DoctrineAdapter::register(
            self::nameOf($params),
            self::endpointOf($params),
            self::platformOf($params),
            $wrapped,
        );

        return Dbal::isVersion4()
            ? new Dbal4\Connection($wrapped, $this->recorder, $connectionName)
            : new Dbal3\Connection($wrapped, $this->recorder, $connectionName);
    }

    /**
     * The platform is derived from the connection parameters rather than from
     * `Driver::getDatabasePlatform()`, whose signature diverged between DBAL 3 and 4.
     *
     * @param array<string, mixed> $params
     */
    private static function platformOf(array $params): string
    {
        $driver = $params['driver'] ?? null;

        if (!\is_string($driver)) {
            return 'unknown';
        }

        return match (true) {
            str_contains($driver, 'mysql') => 'mysql',
            str_contains($driver, 'pgsql') || str_contains($driver, 'postgres') => 'pgsql',
            str_contains($driver, 'sqlite') => 'sqlite',
            str_contains($driver, 'sqlsrv') => 'sqlsrv',
            str_contains($driver, 'oci') => 'oci',
            default => 'unknown',
        };
    }

    /**
     * The name a developer would recognise: the database, nothing else.
     *
     * @param array<string, mixed> $params
     */
    private static function nameOf(array $params): string
    {
        $database = $params['dbname'] ?? $params['path'] ?? null;

        return \is_string($database) && '' !== $database ? $database : 'default';
    }

    /**
     * What actually identifies a database, as opposed to what it is called.
     *
     * A primary and its replica share a `dbname` and differ in host; two tenants may
     * share a host and differ in user. `DoctrineAdapter::register()` keys by this and
     * labels by `nameOf()`, so the common single-database project keeps a readable name
     * and a genuine second database still gets its own entry.
     *
     * Two in-memory SQLite connections are indistinguishable here, and that is accepted:
     * they carry no identity in their parameters at all, and tier 2 does not support
     * SQLite in any case.
     *
     * @param array<string, mixed> $params
     */
    private static function endpointOf(array $params): string
    {
        $parts = [self::nameOf($params)];

        foreach (['host', 'port', 'user', 'unix_socket'] as $key) {
            $value = $params[$key] ?? null;
            $parts[] = \is_scalar($value) ? (string) $value : '';
        }

        return implode('|', $parts);
    }
}
