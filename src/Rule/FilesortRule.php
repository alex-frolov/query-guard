<?php

declare(strict_types=1);

namespace QueryGuard\Rule;

use QueryGuard\Finding\Severity;
use QueryGuard\Platform\Plan;
use QueryGuard\Platform\PlanNode;

/**
 * Sorting without an index.
 *
 * On a small result this is cheap and there is nothing to fix, so the size threshold is
 * as mandatory here as it is for `table-scan`.
 */
final class FilesortRule extends PlanRule
{
    public function id(): string
    {
        return 'filesort';
    }

    protected function severity(): Severity
    {
        return Severity::Warning;
    }

    protected function inspect(PlanNode $node, Plan $plan): ?string
    {
        if (!$node->filesort || !$this->isLargeEnough($node)) {
            return null;
        }

        return sprintf('sorting "%s" without an index', $node->table);
    }
}
