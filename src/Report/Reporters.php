<?php

declare(strict_types=1);

namespace QueryGuard\Report;

use QueryGuard\Mode;

/**
 * Several reporters over one run, in order.
 *
 * Order is part of the contract rather than an accident: a reporter that fails says so by
 * putting a notice on the `Report`, and only a reporter running before the console one
 * can still have that notice printed.
 */
final class Reporters implements Reporter
{
    /**
     * @param list<Reporter> $reporters
     */
    public function __construct(private readonly array $reporters)
    {
    }

    public function report(Report $report, Mode $mode): void
    {
        foreach ($this->reporters as $reporter) {
            $reporter->report($report, $mode);
        }
    }
}
