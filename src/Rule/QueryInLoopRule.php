<?php

declare(strict_types=1);

namespace QueryGuard\Rule;

use QueryGuard\Adapter\Doctrine\DoctrineEnricher;
use QueryGuard\Finding\Finding;
use QueryGuard\Finding\Severity;
use QueryGuard\Query\CallsiteResolver;
use QueryGuard\Query\QueryEvent;
use QueryGuard\Query\Trace;

/**
 * One place in the code issued many queries of **different** shapes.
 *
 * When the shape is the same it is N+1, and its own rule reports it. What is left here is
 * the harsher case: eager loading cannot collapse queries that do not share a shape, so
 * whatever this is, it is not one relation waiting to be preloaded.
 *
 * **The finding states what was counted and stops there, on purpose.** It used to end
 * "— looks like a loop", and on a real Laravel suite that guess was wrong about the two
 * largest clusters in the report. The biggest, 333 findings, was `$request->user()` in a
 * global middleware: Sanctum resolving a token, a user, roles and permissions — six
 * queries in a row, once per request, no iteration anywhere. The second was a single
 * `->get()` whose model declares six default eager loads, i.e. the very thing the
 * `n-plus-one` rule tells people to do. Sending a reader to look for a `foreach` that
 * does not exist costs more than saying less: what the rule actually knows is that this
 * line cost this many queries, and that is worth reporting on its own.
 *
 * Distinguishing the two would need to know where one unit of work ends, which a trace
 * does not carry. Adjacency is not it either — the same suite's media scanner is a real
 * loop whose queries are just as tightly packed.
 *
 * **One exception, where the unit of work is known: a Doctrine `flush()`.** The unit of
 * work writes every pending change from that single call, so a flush of five entities
 * used to be "5 queries of 3 different shapes from one place" — on one Doctrine suite,
 * 418 of 561 findings of this rule pointed at a `flush()` line. The adapter marks which
 * commit wrote each query, and the rule counts a commit as one unit. A `flush()` called
 * from inside a `foreach` is still many units, and still reported.
 *
 * Filtering writes out instead was considered and rejected: on Eloquent half of this
 * rule's findings are writes, and they are real loops — `updateOrCreate()` per element.
 * What made Doctrine different was never the kind of statement, only that one line
 * issues them all.
 *
 * @internal
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
            $units = self::units($group);

            if ($units < $this->threshold) {
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
                message: $units === \count($group)
                    ? sprintf('%d queries of %d different shapes from one place', \count($group), \count($fingerprints))
                    : sprintf(
                        '%d queries of %d different shapes from one place, in %d units of work (a Doctrine flush counts as one)',
                        \count($group),
                        \count($fingerprints),
                        $units,
                    ),
                severity: Severity::Warning,
                callsite: $callsite,
                count: \count($group),
                // a fingerprint will not do here: there are several, and they vary per run
                signature: Finding::signature($this->id(), $callsite, 'mixed'),
            );
        }
    }

    /**
     * How many units of work the group is: every query on its own, except that all
     * queries of one Doctrine commit count once.
     *
     * @param list<QueryEvent> $group
     */
    private static function units(array $group): int
    {
        $commits = [];
        $units = 0;

        foreach ($group as $event) {
            $commit = $event->annotation(DoctrineEnricher::COMMIT);

            if (null === $commit) {
                ++$units;
            } elseif (\is_scalar($commit)) {
                $commits[$event->connection.'|'.$commit] = true;
            }
        }

        return $units + \count($commits);
    }
}
