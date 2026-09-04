<?php

declare(strict_types=1);

namespace QueryGuard\Subscriber\TestRunner;

use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Event\TestRunner\ExecutionFinishedSubscriber as PHPUnitExecutionFinishedSubscriber;
use QueryGuard\Adapter\AdapterSet;
use QueryGuard\Baseline\Baseline;
use QueryGuard\Collector\DefaultQueryCollector;
use QueryGuard\ExtensionConfiguration;
use QueryGuard\Finding\Severity;
use QueryGuard\Mode;
use QueryGuard\Report\Report;
use QueryGuard\Report\Reporter;
use QueryGuard\Rule\Tier2Factory;

/**
 * The end-of-run summary and the exit code.
 */
final class ExecutionFinishedSubscriber implements PHPUnitExecutionFinishedSubscriber
{
    public function __construct(
        private readonly Report $report,
        private readonly Reporter $reporter,
        private readonly DefaultQueryCollector $collector,
        private readonly AdapterSet $adapters,
        private readonly Mode $mode,
        private readonly ?Baseline $generated = null,
        private readonly string $baselinePath = '',
        private readonly ?Tier2Factory $tier2 = null,
        private readonly Severity $failOn = Severity::Warning,
        private readonly ?Baseline $baseline = null,
    ) {
    }

    /**
     * Baseline entries that silenced nothing.
     *
     * A baseline only ever grows otherwise: the finding gets fixed, the entry stays, and
     * from then on the file quietly silences something that no longer exists — including,
     * eventually, a regression that lands on the same rule in the same file.
     *
     * Deliberately not phrased as "obsolete". After `--filter`, or a run that excluded a
     * group, an unmatched entry only means its test did not run, and a tool that called
     * that obsolete would be teaching people to delete live entries.
     *
     * @return list<string>
     */
    private function baselineNotices(): array
    {
        // in regeneration mode nothing is matched against, so everything would look unused
        if (null !== $this->generated || null === $this->baseline) {
            return [];
        }

        $unmatched = $this->baseline->unmatched();

        if ([] === $unmatched) {
            return [];
        }

        return [sprintf(
            "%d baseline entries silenced nothing in this run.\n"
            ."After a full-suite run they are obsolete: regenerate with %s=1 to drop them.\n"
            .'After a filtered run it only means those tests did not execute.',
            \count($unmatched),
            ExtensionConfiguration::GENERATE_BASELINE_ENV,
        )];
    }

    /**
     * A refusal has to be loud. A green summary over an empty trace is exactly how a
     * tool loses trust. Three different kinds of "nothing was collected" are told apart:
     * no ORM at all, an ORM whose interception failed, and interception that worked but
     * saw no queries.
     *
     * @return list<string>
     */
    private function adapterNotices(): array
    {
        if ($this->collector->totalRecorded() > 0) {
            // something is feeding the collector — nothing to complain about
            return [];
        }

        if ($this->adapters->isEmpty()) {
            return ['neither Doctrine nor Eloquent was found in this project — nothing to collect queries with.'];
        }

        $notInstalled = $this->adapters->notInstalled();

        if ([] !== $notInstalled) {
            return [sprintf(
                "an ORM was found (%s) but interception did not take, and no queries were collected.\n%s",
                implode(', ', $notInstalled),
                implode("\n", $this->adapters->installationHints()),
            )];
        }

        return [sprintf(
            "the adapter (%s) is in place, but not a single query ran during the whole suite.\n"
            .'The rules checked nothing; this is not "all clear", it is "nothing to look at".',
            implode(', ', $this->adapters->names()),
        )];
    }

    public function notify(ExecutionFinished $event): void
    {
        foreach ($this->adapterNotices() as $notice) {
            $this->report->addNotice($notice);
        }

        // said even when collection worked: a trace missing whole connections and a
        // complete one both end in the same "no findings" line otherwise
        foreach ($this->adapters->notices() as $notice) {
            $this->report->addNotice($notice);
        }

        foreach ($this->tier2?->notices() ?? [] as $notice) {
            $this->report->addNotice($notice);
        }

        foreach ($this->baselineNotices() as $notice) {
            $this->report->addNotice($notice);
        }

        if ($this->collector->droppedOutsideTests() > 0) {
            $this->report->addNotice(sprintf(
                '%d queries ran outside the boundaries of a test (bootstrap, data providers) and were not passed to the rules.',
                $this->collector->droppedOutsideTests(),
            ));
        }

        if (null !== $this->generated && '' !== $this->baselinePath) {
            $this->report->addNotice(
                $this->generated->save($this->baselinePath)
                    ? sprintf('baseline regenerated: %s, %d findings inside.', $this->baselinePath, $this->generated->count())
                    : sprintf('could not write the baseline to %s.', $this->baselinePath),
            );
        }

        $this->reporter->report($this->report, $this->mode);

        // everything found is printed; only what reaches the `fail-on` severity fails the
        // run. `select-star` is `info` and fires on every Eloquent query there is
        if (null === $this->generated && Mode::Strict === $this->mode && $this->report->hasFindingsAtLeast($this->failOn)) {
            // PHPUnit's event system lets an extension neither fail a test nor change
            // the exit code. The only point that works is shutdown: it runs after
            // PHPUnit has already returned its own code — and therefore replaces it.
            // When PHPUnit is failing the run anyway, its code is the more specific one
            // (2 for errors, 1 for failures) and is left alone.
            if ($this->report->isRunnerFailing()) {
                return;
            }

            register_shutdown_function(static function (): void {
                exit(1);
            });
        }
    }
}
