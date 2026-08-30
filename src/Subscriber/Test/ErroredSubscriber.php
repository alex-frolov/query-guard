<?php

declare(strict_types=1);

namespace QueryGuard\Subscriber\Test;

use PHPUnit\Event\Test\Errored;
use PHPUnit\Event\Test\ErroredSubscriber as PHPUnitErroredSubscriber;
use QueryGuard\Report\Report;

/**
 * The companion of `FailedSubscriber`, for errors rather than failed assertions.
 *
 * Two classes and not one because PHPUnit gives every event its own interface with its
 * own `notify()` signature; a single class cannot implement both.
 */
final class ErroredSubscriber implements PHPUnitErroredSubscriber
{
    public function __construct(private readonly Report $report)
    {
    }

    public function notify(Errored $event): void
    {
        $this->report->markRunnerFailing();
    }
}
