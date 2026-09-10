<?php

declare(strict_types=1);

namespace QueryGuard\Query;

use QueryGuard\TestIdentifier;
use QueryGuard\TestOptions;

/**
 * The ordered queries of a single test — the only thing rules ever see.
 *
 * The trace opens after `setUp()`. Fixture queries are kept apart: a factory creating 50
 * entities in a loop is 50 identical INSERTs from one callsite, i.e. a perfect false
 * positive for the `n-plus-one` rule.
 *
 * Two things can land in that bucket. Everything that reached the database before the
 * trace opened, handed over at construction; and, once the test is running, a query whose
 * call site the project has declared to be preparation — see `Collector\FixtureFilter`.
 * The second kind arrives through `recordFixture()` and is the reason the list is not
 * readonly.
 */
final class Trace
{
    /** @var list<QueryEvent> */
    private array $events = [];

    private int $count = 0;

    private bool $truncated = false;

    /** @var list<QueryEvent> */
    private array $fixtureEvents;

    /**
     * @param list<QueryEvent> $fixtureEvents   queries issued before `Test\Prepared`
     * @param ?int             $maxTraceQueries after this many events, the trace stops
     *                                          holding them — see `record()`
     */
    public function __construct(
        public readonly TestIdentifier $test,
        public readonly TestOptions $options,
        array $fixtureEvents = [],
        private readonly ?int $maxTraceQueries = null,
    ) {
        $this->fixtureEvents = $fixtureEvents;
    }

    /**
     * Past `maxTraceQueries`, the event is counted but not kept: `count()` — and with it
     * `query-count` — stays accurate, while the memory a 100k-query test-import would
     * otherwise hold stops growing. Every other rule sees only the events kept before the
     * cutoff, which `isTruncated()` exists to announce.
     */
    public function record(QueryEvent $event): void
    {
        ++$this->count;

        if (null !== $this->maxTraceQueries && \count($this->events) >= $this->maxTraceQueries) {
            $this->truncated = true;

            return;
        }

        $this->events[] = $event;
    }

    /**
     * @return list<QueryEvent>
     */
    public function events(): array
    {
        return $this->events;
    }

    /**
     * A query the test made that counts as preparation rather than as its subject.
     *
     * Neither `count()` nor `events()` sees it: it is not charged to the `max-queries`
     * budget and no rule is shown it, exactly as if it had happened in `setUp()`. It is
     * counted where `setUp()`'s queries are counted, so `in setUp: N` keeps meaning
     * "queries this test made that nobody examined" rather than developing a blind spot.
     */
    public function recordFixture(QueryEvent $event): void
    {
        $this->fixtureEvents[] = $event;
    }

    /**
     * @return list<QueryEvent>
     */
    public function fixtureEvents(): array
    {
        return $this->fixtureEvents;
    }

    public function count(): int
    {
        return $this->count;
    }

    /**
     * Whether `maxTraceQueries` cut this trace short. The rules still ran — just over
     * part of the test — and the caller is expected to say so loudly rather than let a
     * truncated trace look exactly like a complete one.
     */
    public function isTruncated(): bool
    {
        return $this->truncated;
    }

    public function maxTraceQueries(): ?int
    {
        return $this->maxTraceQueries;
    }

    public function fixtureQueryCount(): int
    {
        return \count($this->fixtureEvents);
    }

    /**
     * Queries grouped by fingerprint — the basis for both deduplication and N+1.
     *
     * @return array<string, list<QueryEvent>>
     */
    public function groupedByFingerprint(): array
    {
        $groups = [];

        foreach ($this->events as $event) {
            $groups[$event->fingerprint()->value()][] = $event;
        }

        return $groups;
    }
}
