<?php

declare(strict_types=1);

namespace QueryGuard\Rule;

use QueryGuard\Finding\Finding;
use QueryGuard\Finding\Severity;
use QueryGuard\Query\CallsiteResolver;
use QueryGuard\Query\QueryEvent;
use QueryGuard\Query\Trace;

/**
 * One place in the code issued many queries of **different** shapes.
 *
 * When the shape is the same it is N+1, and its own rule reports it. What is left here
 * is the harsher case: a dynamic query built inside a loop, every iteration hitting the
 * database differently. Eager loading does not fix that, hence a separate rule.
 */
final class QueryInLoopRule implements Rule
{
    public const DEFAULT_THRESHOLD = 5;

    public function __construct(
        private readonly CallsiteResolver $callsiteResolver,
        private readonly int $threshold = self::DEFAULT_THRESHOLD,
    ) {
    }

    public function id(): string
    {
        return 'query-in-loop';
    }

    public function check(Trace $trace): iterable
    {
        /** @var array<string, list<QueryEvent>> $groups */
        $groups = [];

        foreach ($trace->events() as $event) {
            $callsite = $event->callsite($this->callsiteResolver);

            if (null === $callsite) {
                continue;
            }

            $groups[(string) $callsite][] = $event;
        }

        foreach ($groups as $group) {
            if (\count($group) < $this->threshold) {
                continue;
            }

            $fingerprints = [];

            foreach ($group as $event) {
                $fingerprints[$event->fingerprint()->value()] = true;
            }

            if (\count($fingerprints) < 2) {
                // a single shape is either N+1 or a duplicate — other rules cover it
                continue;
            }

            $first = $group[0];
            $callsite = $first->callsite($this->callsiteResolver);

            yield new Finding(
                rule: $this->id(),
                test: $trace->test,
                message: sprintf(
                    '%d queries of %d different shapes from one place — looks like a loop',
                    \count($group),
                    \count($fingerprints),
                ),
                severity: Severity::Warning,
                callsite: $callsite,
                count: \count($group),
                // a fingerprint will not do here: there are several, and they vary per run
                signature: Finding::signature($this->id(), $callsite, 'mixed'),
            );
        }
    }
}
