<?php

declare(strict_types=1);

namespace QueryGuard\Adapter\Doctrine;

use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\Middleware as MiddlewareInterface;
use QueryGuard\Adapter\QueryEnricher;

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

    public function __construct(?QueryEnricher $enricher = null)
    {
        // enrichment is on by default: without it the `n-plus-one` rule cannot tell lazy
        // loading from a batch loader called several times, and a project should not have
        // to wire anything by hand to get that
        $this->recorder = new Recorder($enricher ?? new DoctrineEnricher());
    }

    public function wrap(DriverInterface $driver): DriverInterface
    {
        return new Driver($driver, $this->recorder);
    }
}
