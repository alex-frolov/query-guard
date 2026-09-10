<?php

declare(strict_types=1);

namespace QueryGuard\Rule;

use QueryGuard\Finding\Severity;
use QueryGuard\Platform\Plan;
use QueryGuard\Platform\PlanNode;
use QueryGuard\Platform\ScanType;

/**
 * A full scan of a table or of an entire index, even though the table does have indexes.
 *
 * Both are the same cost — every row (or every index entry) is read whether or not a
 * condition narrows it — so `ScanType::readsWholeRelation()` covers `FullTable` and
 * `FullIndex` together here instead of leaving the second one unflagged. The wording
 * differs because the diagnosis does: a `FullTable` scan means the optimiser ignored an
 * index that could have applied, while a `FullIndex` scan means an index *was* used, just
 * with nothing to narrow it — walking one index end to end instead of the whole table.
 *
 * When there is no index at all it is `no-possible-index` — a different diagnosis: there
 * you create an index, here you work out why the scan reads everything anyway.
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
        if (!$node->scanType->readsWholeRelation() || true === $node->hasNoPossibleIndex()) {
            return null;
        }

        if (!$this->isLargeEnough($node, $plan)) {
            return null;
        }

        $rows = number_format((float) ($this->plans->rowsFor($node, $plan) ?? 0), 0, '.', ' ');

        if (ScanType::FullIndex === $node->scanType) {
            return sprintf(
                'full scan of the "%s" index on "%s" (~%s rows) — nothing narrowed it',
                $node->usedIndex ?? '?',
                $node->table,
                $rows,
            );
        }

        return sprintf(
            'full scan of "%s" (~%s rows) although indexes exist',
            $node->table,
            $rows,
        );
    }
}
