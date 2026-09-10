<?php

declare(strict_types=1);

namespace QueryGuard\Rule;

use QueryGuard\Finding\Finding;
use QueryGuard\Query\Trace;

/**
 * Runs the rules over a trace, honouring `#[IgnoreRule]`.
 *
 * Every rule is here from the start. There used to be a second, deferred set for tier 2,
 * which could not be built until a database connection existed — that constraint is gone
 * now that `PlanProvider` resolves a connection when it first sees a query from it.
 */
final class RuleEngine
{
    /**
     * @param list<Rule> $rules
     */
    public function __construct(private readonly array $rules)
    {
    }

    /**
     * @return list<Finding>
     */
    public function run(Trace $trace): array
    {
        $findings = [];

        foreach ($this->rules as $rule) {
            if ($trace->options->isIgnored($rule->id())) {
                continue;
            }

            foreach ($rule->check($trace) as $finding) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }
}
