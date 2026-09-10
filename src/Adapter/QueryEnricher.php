<?php

declare(strict_types=1);

namespace QueryGuard\Adapter;

/**
 * ORM-specific enrichment of a query: entity name, association name, and whether
 * the query came from lazy loading.
 *
 * It takes raw stack frames **including the objects** (`DEBUG_BACKTRACE_PROVIDE_OBJECT`)
 * and returns flat annotations. Deliberately so, rather than `QueryEvent → QueryEvent`
 * as originally designed: those frames hold live entities and collections, and keeping
 * them in the event would make a test's trace pin down everything that ever touched
 * the database. An annotation is just strings; it can live as long as it likes.
 *
 * A separate interface rather than a method on the interceptor: the interceptor lives
 * in the driver layer and knows nothing about the ORM, while enrichment knows nothing else.
 */
interface QueryEnricher
{
    /**
     * @param list<array<string, mixed>> $stack
     *
     * @return array<string, mixed>
     */
    public function annotate(array $stack): array;
}
