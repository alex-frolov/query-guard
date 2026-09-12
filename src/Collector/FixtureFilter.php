<?php

declare(strict_types=1);

namespace QueryGuard\Collector;

use QueryGuard\Query\CallsiteResolver;
use QueryGuard\Query\QueryEvent;

/**
 * Which queries are the stand being built rather than the application being exercised.
 *
 * The original answer was the phase of the test: everything before `Test\Prepared` came
 * out of `setUp()` and is fixture work, everything after is the subject. That holds for
 * the suites it was designed against — Doctrine projects prepare data in `setUp()` — and
 * it does not hold for Laravel, where calling factories on the first line of the test body
 * is the ordinary style. On one 1 617-test suite that put 2 023 of 3 173 findings (64%)
 * on factory and test files: every one of them a true statement about a factory, and not
 * one of them about the application.
 *
 * So there is a second answer, by **call site** rather than by phase: a query issued from
 * a path the project has named goes into the fixture bucket wherever in the test it
 * happened. `fixture-paths` is that list.
 *
 * **Why not `skip-paths`.** That list is about *naming* — it steps over a frame so the
 * callsite lands on the next one — and using it here moves the blame rather than removing
 * it: point it at a factory directory and the findings reappear against the test line that
 * called the factory; point it at the tests too and 41% of them come back with no callsite
 * at all. This filter does not touch how a finding is named. It decides whether the query
 * is examined, and puts the ones that are not where `setUp()`'s already go, so that
 * `in setUp: N` in the summary stays a true count of preparation rather than becoming a
 * number with a hole in it.
 *
 * Migrations are the same thing seen from another angle. `LazilyRefreshDatabase` builds
 * the schema on first use, i.e. inside the body of whichever test ran first, so the schema
 * build is traced as that test's work — and under a parallel runner it happens once per
 * worker, which on the same suite was 11 tests carrying ~926 extra findings each.
 * `database/migrations` in this list is what makes that stop.
 *
 * **Resolution stays lazy when the list is empty**, which is the default. A project that
 * has not configured this pays nothing: no callsite is resolved earlier than it used to
 * be. A project that has configured it resolves the callsite once, at record time, and
 * the resolver is the very one the rules use later — `QueryEvent` memoises per resolver,
 * so the second lookup is free.
 *
 * @internal
 */
final class FixtureFilter
{
    /** @var list<string> */
    private readonly array $patterns;

    /** @var array<string, bool> */
    private array $verdicts = [];

    /**
     * @param list<string> $fragments path fragments in `skip-paths` notation
     */
    public function __construct(array $fragments, private readonly CallsiteResolver $resolver)
    {
        $this->patterns = CallsiteResolver::pathPatterns($fragments);
    }

    public function isEmpty(): bool
    {
        return [] === $this->patterns;
    }

    /**
     * A query with no resolvable call site is **not** treated as a fixture. Every frame
     * of its stack was skipped, which says nothing about what the query was for, and
     * quietly moving it out of the rules' way would hide a real finding behind a
     * configuration nobody wrote with it in mind.
     */
    public function isFixture(QueryEvent $event): bool
    {
        if ([] === $this->patterns) {
            return false;
        }

        $callsite = $event->callsite($this->resolver);

        if (null === $callsite) {
            return false;
        }

        return $this->verdicts[$callsite->file] ??= CallsiteResolver::pathMatches($callsite->file, $this->patterns);
    }
}
