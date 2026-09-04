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
 */
final class Trace
{
    /** @var list<QueryEvent> */
    private array $events = [];

    /**
     * @param list<QueryEvent> $fixtureEvents queries issued before `Test\Prepared`
     */
    public function __construct(
        public readonly TestIdentifier $test,
        public readonly TestOptions $options,
        private readonly array $fixtureEvents = [],
    ) {
    }

    public function record(QueryEvent $event): void
    {
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
     * @return list<QueryEvent>
     */
    public function fixtureEvents(): array
    {
        return $this->fixtureEvents;
    }

    public function count(): int
    {
        return \count($this->events);
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
