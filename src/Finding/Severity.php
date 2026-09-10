<?php

declare(strict_types=1);

namespace QueryGuard\Finding;

/**
 * How sure the tool is about a finding — and, through `fail-on`, what it costs.
 *
 * The scale is about certainty rather than about how bad the query is: `error` means the
 * adapter recognised lazy loading and named the association, `warning` that only the
 * shape heuristic fired, `info` that the finding is a style note nobody should be failed
 * over by default.
 */
enum Severity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Error = 'error';

    /**
     * Ordering for the summary and for the `fail-on` threshold. Higher is more certain.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Info => 0,
            self::Warning => 1,
            self::Error => 2,
        };
    }

    public function atLeast(self $threshold): bool
    {
        return $this->rank() >= $threshold->rank();
    }
}
