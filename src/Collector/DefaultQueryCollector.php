<?php

declare(strict_types=1);

namespace QueryGuard\Collector;

use QueryGuard\Query\QueryEvent;
use QueryGuard\Query\Trace;
use QueryGuard\TestIdentifier;
use QueryGuard\TestOptions;

/**
 * Sorts queries into three buckets depending on the phase of the run.
 *
 * The extension's subscribers switch the phase; the collector itself knows nothing
 * about PHPUnit.
 */
final class DefaultQueryCollector implements QueryCollector
{
    private Phase $phase = Phase::Idle;

    /** @var list<QueryEvent> */
    private array $fixtureEvents = [];

    private ?Trace $trace = null;

    private int $droppedOutsideTests = 0;

    private int $totalRecorded = 0;

    private bool $paused = false;

    public function record(QueryEvent $event): void
    {
        if ($this->paused) {
            return;
        }

        ++$this->totalRecorded;

        switch ($this->phase) {
            case Phase::Test:
                $this->trace?->record($event);

                break;

            case Phase::Fixtures:
                $this->fixtureEvents[] = $event;

                break;

            case Phase::Idle:
                $this->droppedOutsideTests++;

                break;
        }
    }

    public function isRecording(): bool
    {
        return !$this->paused && Phase::Idle !== $this->phase;
    }

    /**
     * Tier 2 talks to the database itself (EXPLAIN). Those queries must not land in the
     * trace, or the tool would start counting its own traffic.
     */
    public function pause(): void
    {
        $this->paused = true;
    }

    public function resume(): void
    {
        $this->paused = false;
    }

    public function beginFixtures(): void
    {
        $this->phase = Phase::Fixtures;
        $this->fixtureEvents = [];
        $this->trace = null;
    }

    /**
     * Opens the trace for a test. Whatever accumulated before this moment moves into
     * the trace as a separate bucket — a count, not input for the rules.
     */
    public function beginTrace(TestIdentifier $test, TestOptions $options): Trace
    {
        $this->trace = new Trace($test, $options, $this->fixtureEvents);
        $this->fixtureEvents = [];
        $this->phase = Phase::Test;

        return $this->trace;
    }

    public function endTrace(): ?Trace
    {
        $trace = $this->trace;

        $this->trace = null;
        $this->fixtureEvents = [];
        $this->phase = Phase::Idle;

        return $trace;
    }

    public function phase(): Phase
    {
        return $this->phase;
    }

    public function totalRecorded(): int
    {
        return $this->totalRecorded;
    }

    public function droppedOutsideTests(): int
    {
        return $this->droppedOutsideTests;
    }
}
