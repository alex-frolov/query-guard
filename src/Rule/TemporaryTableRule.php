<?php

declare(strict_types=1);

namespace QueryGuard\Rule;

use QueryGuard\Finding\Severity;
use QueryGuard\Platform\Plan;
use QueryGuard\Platform\PlanNode;

/**
 * `Using temporary` — MySQL puts an intermediate result into a temporary table.
 *
 * A platform-specific rule: PostgreSQL's closest equivalent (`HashAggregate`) is a
 * normal way to group, and complaining about it would mean false positives. So on
 * PostgreSQL the rule does not work — and says so.
 */
final class TemporaryTableRule extends PlanRule
{
    public function id(): string
    {
        return 'temporary-table';
    }

    protected function severity(): Severity
    {
        return Severity::Warning;
    }

    protected function inspect(PlanNode $node, Plan $plan): ?string
    {
        if (true !== $this->plans->driverFor($plan)?->reportsTemporaryTable()) {
            return null;
        }

        if (!$node->temporaryTable || !$this->isLargeEnough($node, $plan)) {
            return null;
        }

        return sprintf('temporary table while processing "%s"', $node->table);
    }
}
