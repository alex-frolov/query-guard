<?php

declare(strict_types=1);

namespace QueryGuard\Platform;

use QueryGuard\Adapter\Explainer;

/**
 * Where a plan comes from: one connection's `Explainer` and the driver that reads its
 * output.
 *
 * The two travel together because they are two halves of one fact. The explainer knows
 * which database answered; the driver knows how to read that database's `EXPLAIN`.
 * Pairing them per connection is what stops a MySQL plan from being parsed as PostgreSQL
 * on a project that has both.
 */
final readonly class PlanSource
{
    public function __construct(
        public Explainer $explainer,
        public PlatformDriver $driver,
    ) {
    }
}
