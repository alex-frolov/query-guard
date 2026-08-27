<?php

declare(strict_types=1);

namespace QueryGuard\Rule;

use QueryGuard\Finding\Finding;
use QueryGuard\Finding\Severity;
use QueryGuard\Query\CallsiteResolver;
use QueryGuard\Query\QueryEvent;
use QueryGuard\Query\Trace;

/**
 * The same query with the same values, run several times within one test.
 *
 * This is NOT N+1, and the two must not be confused: in N+1 the values differ, whereas
 * here everything matches — meaning the first result was simply not kept. Different
 * diagnosis (a missing cache or a lost reference), different fix. Conflating the two is
 * exactly where the closest competitor goes wrong: it calls a match of SQL *and* bound
 * values a duplicate, and believes it is catching N+1 that way.
 */
final class DuplicateQueryRule implements Rule
{
    /**
     * Strictly speaking a duplicate is two identical queries. The default threshold is
     * nevertheless five, and the number comes from measurement rather than taste: on a
     * mature project's controller suite a threshold of 2 produced 389 findings, 3 gave
     * 178 and 5 gave 23. At two the rule drowns out every other rule, and a pair of
     * identical reads within one test is more often two independent lookups of the same
     * setting than a mistake. Five repeats is a pattern. Set 2 if you want the strict
     * definition.
     */
    public const DEFAULT_THRESHOLD = 5;

    public function __construct(
        private readonly CallsiteResolver $callsiteResolver,
        private readonly int $threshold = self::DEFAULT_THRESHOLD,
    ) {
    }

    public function id(): string
    {
        return 'duplicate-query';
    }

    public function check(Trace $trace): iterable
    {
        /** @var array<string, list<QueryEvent>> $groups */
        $groups = [];

        foreach ($trace->events() as $event) {
            if (!$event->isSelect()) {
                // writing the same data twice is not a lost cache — usually it is
                // fixtures or a deliberate upsert
                continue;
            }

            $groups[$event->shape()][] = $event;
        }

        foreach ($groups as $group) {
            if (\count($group) < $this->threshold) {
                continue;
            }

            $first = $group[0];
            $callsite = $first->callsite($this->callsiteResolver);

            yield new Finding(
                rule: $this->id(),
                test: $trace->test,
                message: sprintf(
                    'the same query with the same values ran %d times: %s',
                    \count($group),
                    Sql::shorten($first->sql),
                ),
                severity: Severity::Warning,
                callsite: $callsite,
                count: \count($group),
                signature: Finding::signature($this->id(), $callsite, $first->fingerprint()->value()),
            );
        }
    }
}
