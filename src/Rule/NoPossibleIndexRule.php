<?php

declare(strict_types=1);

namespace QueryGuard\Rule;

use QueryGuard\Finding\Severity;
use QueryGuard\Platform\Plan;
use QueryGuard\Platform\PlanNode;

/**
 * No suitable index exists in the schema at all.
 *
 * **The only plan rule without a size threshold**, and that is not a concession: a
 * missing index is a fact about the schema rather than about the data, and it holds on
 * an empty table too. In spirit the rule belongs to tier 1; it ended up in tier 2 only
 * because EXPLAIN is what reveals it.
 *
 * Silent on PostgreSQL, which does not answer "which indexes could have applied" —
 * and explicitly so: the summary says as much out loud.
 */
final class NoPossibleIndexRule extends PlanRule
{
    public function id(): string
    {
        return 'no-possible-index';
    }

    protected function severity(): Severity
    {
        return Severity::Error;
    }

    protected function inspect(PlanNode $node, Plan $plan): ?string
    {
        if (true !== $node->hasNoPossibleIndex()) {
            return null;
        }

        return sprintf('no suitable index on "%s" — a scan with no alternative', $node->table);
    }
}
