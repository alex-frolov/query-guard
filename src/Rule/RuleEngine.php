<?php

declare(strict_types=1);

namespace QueryGuard\Rule;

use QueryGuard\Finding\Finding;
use QueryGuard\Query\Trace;

/**
 * Runs the rules over a trace, honouring `#[IgnoreRule]`.
 */
final class RuleEngine
{
    /**
     * @param list<Rule>                    $rules
     * @param (\Closure(): list<Rule>)|null $deferred rules that cannot be built at
     *                                                start-up. Tier 2 needs a live connection, and none exists when the
     *                                                extension loads — a Doctrine middleware is installed before the
     *                                                connection is even created
     */
    public function __construct(
        private array $rules,
        private ?\Closure $deferred = null,
    ) {
    }

    /**
     * @return list<Finding>
     */
    public function run(Trace $trace): array
    {
        if (null !== $this->deferred) {
            $late = ($this->deferred)();

            if ([] !== $late) {
                $this->rules = [...$this->rules, ...$late];
                $this->deferred = null;
            }
        }

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

    /**
     * @return list<string>
     */
    public function ruleIds(): array
    {
        return array_map(static fn (Rule $rule): string => $rule->id(), $this->rules);
    }
}
