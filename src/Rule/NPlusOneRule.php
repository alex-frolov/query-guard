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
 * The flagship rule: one fingerprint, N times, from one place in the code.
 *
 * It works off the trace and knows nothing about the ORM or the query plan. That is not
 * a simplification but a consequence of what N+1 is: a property of a *sequence* of
 * queries, not of any one query. Verified on MySQL, where plan analysis works in full:
 * the plan of each of five lazy fetches is flawless (`const`, `PRIMARY`, one row, no
 * issues), and N+1 simply cannot be derived from plans.
 *
 * Two conditions, and the second matters as much as the first:
 *
 * 1. **One shape and one place.** Grouped by (fingerprint, callsite): a lazy association
 *    fetched in a loop leaves from the very same line of code.
 * 2. **The values differ.** If every repeat matches in both SQL and bound values, this is
 *    a duplicate (a cache that did not work), not N+1, and `duplicate-query` should be
 *    the one to report it. Mixing the two is not allowed: different diagnosis, different fix.
 *
 * When the adapter managed to recognise lazy initialisation, the finding names the
 * association and is raised to `error` — there is nothing left to guess. Without that
 * evidence only the shape heuristic remains, and severity stays `warning`.
 *
 * Only reads are considered. A run against a real project showed why that is not a
 * detail: controller tests often build fixtures inside the test body, and without this
 * condition the first "N+1" reported was 502 identical INSERTs from a tag factory.
 */
final class NPlusOneRule implements Rule
{
    public const DEFAULT_THRESHOLD = 3;

    public function __construct(
        private readonly CallsiteResolver $callsiteResolver,
        private readonly int $threshold = self::DEFAULT_THRESHOLD,
    ) {
    }

    public function id(): string
    {
        return 'n-plus-one';
    }

    public function check(Trace $trace): iterable
    {
        foreach ($this->group($trace) as $group) {
            if (\count($group) < $this->threshold) {
                continue;
            }

            $shapes = [];

            foreach ($group as $event) {
                $shapes[$event->shape()] = true;
            }

            if (\count($shapes) < 2) {
                // every repeat is identical throughout — that is a duplicate, not N+1
                continue;
            }

            $first = $group[0];
            $callsite = $first->callsite($this->callsiteResolver);
            $lazy = self::lazyLoading($group);

            yield new Finding(
                rule: $this->id(),
                test: $trace->test,
                message: $lazy ?? sprintf(
                    '%d queries of the same shape from one place, different values: %s',
                    \count($group),
                    Sql::shorten($first->sql),
                ),
                // lazy loading is N+1 by definition rather than a guess from the shape
                severity: null !== $lazy ? Severity::Error : Severity::Warning,
                callsite: $callsite,
                count: \count($group),
                signature: Finding::signature($this->id(), $callsite, $first->fingerprint()->value()),
            );
        }
    }

    /**
     * When the adapter reports that the queries came out of lazy initialisation, say so
     * plainly and name the association. The evidence is required for EVERY repeat: one
     * lazy load among three ordinary queries of the same shape proves nothing.
     *
     * @param list<QueryEvent> $group
     */
    private static function lazyLoading(array $group): ?string
    {
        $entities = [];
        $associations = [];

        foreach ($group as $event) {
            $kind = $event->annotation(DoctrineEnricher::KIND);

            if (null === $kind) {
                return null;
            }

            $entity = $event->annotation(DoctrineEnricher::ENTITY);
            $association = $event->annotation(DoctrineEnricher::ASSOCIATION);

            $entities[\is_string($entity) ? $entity : ''] = true;
            $associations[\is_string($association) ? $association : ''] = true;
        }

        if (1 !== \count($entities)) {
            return null;
        }

        $entity = array_key_first($entities);
        $association = 1 === \count($associations) ? array_key_first($associations) : '';

        if ('' === $entity) {
            return sprintf('lazy loading, %d queries', \count($group));
        }

        if ('' === $association) {
            return sprintf('%s — lazy-loaded entity, %d queries', $entity, \count($group));
        }

        return sprintf('%s::$%s — lazy-loaded association, %d queries', $entity, $association, \count($group));
    }

    /**
     * @return list<list<QueryEvent>>
     */
    private function group(Trace $trace): array
    {
        $groups = [];

        foreach ($trace->events() as $event) {
            if (!$event->isSelect()) {
                // repeated writes are a fixture factory or a batch insert — a different
                // phenomenon with a different fix
                continue;
            }

            if (Sql::isBatchFetch($event->sql)) {
                // fetching many rows in one query is the cure for N+1, not the disease
                continue;
            }

            $callsite = $event->callsite($this->callsiteResolver);
            $key = $event->fingerprint()->value().'@'.($callsite?->__toString() ?? 'unknown');
            $groups[$key][] = $event;
        }

        return array_values($groups);
    }
}
