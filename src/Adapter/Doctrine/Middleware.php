<?php

declare(strict_types=1);

namespace QueryGuard\Adapter\Doctrine;

use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\Middleware as MiddlewareInterface;

/**
 * Our own DBAL middleware — a Driver → Connection → Statement decorator.
 *
 * Doctrine's stock `Doctrine\DBAL\Logging\Middleware` will not do: it records a query
 * on the "Executing" event, i.e. BEFORE it runs, and cannot provide a duration at all.
 * That limitation is stated in its own docblock, and it is why a well-known competitor
 * reports `getTotalQueryTime()` as zero and passes `assertMaxQueryTime(0.001)` on six
 * real queries.
 *
 * Wiring it up in Symfony:
 *
 *     # config/services_test.yaml
 *     QueryGuard\Adapter\Doctrine\Middleware:
 *         tags: ['doctrine.middleware']
 */
final class Middleware implements MiddlewareInterface
{
    private readonly Recorder $recorder;

    /**
     * Takes no arguments, and used to take an optional `QueryEnricher`.
     *
     * Nothing ever passed one, and nothing useful could have: the only reader of the
     * annotations is `n-plus-one`, and it reads the keys `DoctrineEnricher` writes. A
     * replacement could at best reproduce that class, and at worst switch it off — lazy
     * loading stops being told apart from a batch loader, and every N+1 drops back to a
     * warning. That is not an extension point, and at 1.0 it would have become one to
     * support for years.
     */
    public function __construct()
    {
        $this->recorder = new Recorder(new DoctrineEnricher());
    }

    public function wrap(DriverInterface $driver): DriverInterface
    {
        return new Driver($driver, $this->recorder);
    }
}
