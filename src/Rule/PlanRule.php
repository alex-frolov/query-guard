<?php

declare(strict_types=1);

namespace QueryGuard\Rule;

use QueryGuard\Finding\Finding;
use QueryGuard\Platform\Plan;
use QueryGuard\Platform\PlanNode;
use QueryGuard\Platform\PlanProvider;
use QueryGuard\Query\CallsiteResolver;
use QueryGuard\Query\Trace;

/**
 * The part shared by tier 2 rules: get a plan and walk its nodes.
 *
 * **The size threshold is a condition of correctness, not a preference.** A competing
 * tool reported `error: Full table scan` on a five-row table because InnoDB's own
 * estimate lied (it said 15 against a threshold of 10). Without volume, tier 2 is not
 * merely useless — it is harmful.
 */
abstract class PlanRule implements Rule
{
    public const DEFAULT_MIN_ROWS = 1000;

    public function __construct(
        protected readonly PlanProvider $plans,
        protected readonly CallsiteResolver $callsiteResolver,
        protected readonly int $minRows = self::DEFAULT_MIN_ROWS,
    ) {
    }

    public function check(Trace $trace): iterable
    {
        $seen = [];

        foreach ($trace->events() as $event) {
            $plan = $this->plans->planFor($event);

            if (null === $plan) {
                continue;
            }

            foreach ($plan->nodes as $node) {
                $message = $this->inspect($node, $plan);

                if (null === $message) {
                    continue;
                }

                $callsite = $event->callsite($this->callsiteResolver);
                $signature = Finding::signature($this->id(), $callsite, $event->fingerprint()->value().'#'.$node->table);

                if (isset($seen[$signature])) {
                    continue;
                }

                $seen[$signature] = true;

                yield new Finding(
                    rule: $this->id(),
                    test: $trace->test,
                    message: $message,
                    severity: $this->severity(),
                    callsite: $callsite,
                    signature: $signature,
                );
            }
        }
    }

    /**
     * Whether the table holds enough rows for the plan to mean anything at all.
     */
    protected function isLargeEnough(PlanNode $node): bool
    {
        $rows = $this->plans->rowsFor($node);

        // size unknown — stay quiet, there is nothing to judge by
        return null !== $rows && $rows >= $this->minRows;
    }

    abstract protected function severity(): \QueryGuard\Finding\Severity;

    /**
     * @return string|null the finding text, or null when the node is fine
     */
    abstract protected function inspect(PlanNode $node, Plan $plan): ?string;
}
