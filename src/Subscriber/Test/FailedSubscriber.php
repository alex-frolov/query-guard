<?php

declare(strict_types=1);

namespace QueryGuard\Subscriber\Test;

use PHPUnit\Event\Test\Failed;
use PHPUnit\Event\Test\FailedSubscriber as PHPUnitFailedSubscriber;
use QueryGuard\Report\Report;

/**
 * Notes that PHPUnit itself is going to fail the run.
 *
 * `strict` mode forces a non-zero exit code from a shutdown function, which is the only
 * point that runs after PHPUnit has returned its own. That also means it *overwrites*
 * PHPUnit's code — turning a `2` (errors) into a `1` (failures) and losing the more
 * specific answer. So when PHPUnit already has a reason to fail, query-guard keeps out
 * of the way and only prints its summary.
 */
final class FailedSubscriber implements PHPUnitFailedSubscriber
{
    public function __construct(private readonly Report $report)
    {
    }

    public function notify(Failed $event): void
    {
        $this->report->markRunnerFailing();
    }
}
