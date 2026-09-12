<?php

declare(strict_types=1);

namespace QueryGuard\Subscriber\Test;

use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber as PHPUnitFinishedSubscriber;
use QueryGuard\Baseline\Baseline;
use QueryGuard\Collector\DefaultQueryCollector;
use QueryGuard\Report\Report;
use QueryGuard\Rule\RuleEngine;

/**
 * Closes the trace and runs the rules over it.
 *
 * @internal
 */
final class FinishedSubscriber implements PHPUnitFinishedSubscriber
{
    public function __construct(
        private readonly DefaultQueryCollector $collector,
        private readonly RuleEngine $engine,
        private readonly Report $report,
        private readonly Baseline $baseline,
        private readonly ?Baseline $generated = null,
    ) {
    }

    public function notify(Finished $event): void
    {
        $trace = $this->collector->endTrace();

        if (null === $trace) {
            return;
        }

        $findings = $this->engine->run($trace);

        if ($trace->isTruncated()) {
            // loud on purpose: a truncated trace and a complete one must never look the
            // same in the summary, or the rules' incomplete view goes unnoticed
            $this->report->addNotice(sprintf(
                'trace truncated at %d queries — the rules saw part of this test (%s)',
                $trace->maxTraceQueries(),
                $trace->test->label,
            ));
        }

        if (null !== $this->generated) {
            // regeneration mode: everything found goes into the file, not the summary
            foreach ($findings as $finding) {
                $this->generated->add($finding);
            }

            $this->report->addTrace($trace, []);
            $this->report->suppress(\count($findings));

            return;
        }

        $fresh = [];
        $suppressed = 0;

        foreach ($findings as $finding) {
            if ($this->baseline->contains($finding)) {
                ++$suppressed;

                continue;
            }

            $fresh[] = $finding;
        }

        $this->report->suppress($suppressed);
        $this->report->addTrace($trace, $fresh);
    }
}
