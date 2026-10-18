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
 * A table read alone with nothing filtering it is not judged: without a WHERE there is
 * nothing for an index to do, and the scan is the query's own intent.
 *
 * Silent on PostgreSQL, which does not answer "which indexes could have applied" —
 * and explicitly so: the summary says as much out loud.
 *
 * @internal
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

    protected function inspect(PlanNode $node, Plan $plan, string $table): ?string
    {
        // a derived table never has an index: a subquery has no schema to fix. Its own
        // tables are separate nodes and are judged on their own
        if ($node->derived || true !== $node->hasNoPossibleIndex()) {
            return null;
        }

        // nothing filters the rows — `findAll()` on a lookup table, preloading settings.
        // No index could narrow a read that wants every row, and calling it "a scan with
        // no alternative" was 825 of 927 findings of this rule on one MySQL suite. Null —
        // the driver cannot tell, which includes a table in a join — is judged as before
        if (false === $node->hasCondition) {
            return null;
        }

        return sprintf('no suitable index on %s — a scan with no alternative', $table);
    }
}
