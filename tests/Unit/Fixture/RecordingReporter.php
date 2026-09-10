<?php

declare(strict_types=1);

namespace QueryGuard\Test\Unit\Fixture;

use QueryGuard\Mode;
use QueryGuard\Report\Report;
use QueryGuard\Report\Reporter;

/**
 * Keeps what it was handed instead of printing it. What the printed summary looks like
 * is `ConsoleReporterTest`'s question; what reaches the reporter is this one's.
 */
final class RecordingReporter implements Reporter
{
    public ?Report $report = null;

    public ?Mode $mode = null;

    public function report(Report $report, Mode $mode): void
    {
        $this->report = $report;
        $this->mode = $mode;
    }
}
