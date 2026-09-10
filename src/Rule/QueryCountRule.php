<?php

declare(strict_types=1);

namespace QueryGuard\Rule;

use QueryGuard\Finding\Finding;
use QueryGuard\Finding\Severity;
use QueryGuard\Query\CallsiteResolver;
use QueryGuard\Query\Trace;

/**
 * A query budget per test.
 *
 * The simplest tier 1 rule. Without a threshold in the configuration it stays silent:
 * a tool that turns half of a suite red on its first install gets removed the same day.
 */
final class QueryCountRule implements Rule
{
    public function __construct(
        private readonly ?int $maximum,
        private readonly CallsiteResolver $callsiteResolver,
    ) {
    }

    public function id(): string
    {
        return 'query-count';
    }

    public function check(Trace $trace): iterable
    {
        $maximum = $trace->options->allowQueries ?? $this->maximum;

        if (null === $maximum || $trace->count() <= $maximum) {
            return;
        }

        $first = $trace->events()[0] ?? null;

        yield new Finding(
            rule: $this->id(),
            test: $trace->test,
            message: sprintf('%d queries against a budget of %d', $trace->count(), $maximum),
            severity: Severity::Warning,
            callsite: $first?->callsite($this->callsiteResolver),
            count: $trace->count(),
            // the budget is set per test rather than per place in the code, so is the key
            signature: Finding::signature($this->id(), null, $trace->test->label),
        );
    }
}
