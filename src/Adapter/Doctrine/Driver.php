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
        $connectionName = self::nameOf($params);
        /** @phpstan-ignore argument.type (DBAL describes connection parameters with a
         *  strict array shape; a decorator is fine with whatever it was handed) */
        $wrapped = parent::connect($params);

        DoctrineAdapter::markConnected($connectionName, self::platformOf($params), $wrapped);

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
     * @param array<string, mixed> $params
     */
    private static function nameOf(array $params): string
    {
        $database = $params['dbname'] ?? $params['path'] ?? null;

        return \is_string($database) && '' !== $database ? $database : 'default';
    }
}
