<?php

declare(strict_types=1);

namespace QueryGuard\Rule;

use QueryGuard\Finding\Severity;
use QueryGuard\Platform\Plan;
use QueryGuard\Platform\PlanNode;
use QueryGuard\Platform\ScanType;

/**
 * A full scan even though the table does have indexes.
 *
 * When there is no index at all it is `no-possible-index` — a different diagnosis: there
 * you create an index, here you work out why the optimiser did not use one.
 */
final class TableScanRule extends PlanRule
{
    public function id(): string
    {
        return 'table-scan';
    }

    protected function severity(): Severity
    {
        return Severity::Error;
    }

    protected function inspect(PlanNode $node, Plan $plan): ?string
    {
        if (ScanType::FullTable !== $node->scanType || true === $node->hasNoPossibleIndex()) {
            return null;
        }

        if (!$this->isLargeEnough($node, $plan)) {
            return null;
        }

        return sprintf(
            'full scan of "%s" (~%s rows) although indexes exist',
            $node->table,
            number_format((float) ($this->plans->rowsFor($node, $plan) ?? 0), 0, '.', ' '),
        );
    }
}
