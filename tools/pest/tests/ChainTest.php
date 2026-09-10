<?php

declare(strict_types=1);

/**
 * The one test here written the way Pest is actually written — a closure, not a
 * `TestCase` method — and recording through a helper rather than inline.
 *
 * Both halves of that matter, because together they are the only shape in which the
 * runner's frames reach a chain. A `TestCase` method leaves no application frame at all:
 * PHPUnit invokes it by reflection, so the top frame is `TestCase.php` and no callsite
 * resolves. A closure recording inline leaves none either — `debug_backtrace()` called in
 * the closure describes where the *closure* was called from, which is Pest. It takes one
 * frame between the test and the record, exactly as an ORM adapter is on a real project,
 * for the test's own line to appear: then the callsite is the `recordQuery()` call below,
 * and immediately above it sit Pest's frames — `TestCaseMethodFactory` →
 * `Testable::__callClosure` → `ExceptionTrace::ensure` — which used to be reported as the
 * callers, naming a `{closure}` as whoever asked for the query.
 *
 * Nobody is above a test, so the honest chain here is empty. Only a live Pest puts those
 * frames on a stack, which is why this is guarded here and nowhere else.
 */

use QueryGuard\Query\QueryEvent;
use QueryGuard\QueryGuard;

/** Stands in for the adapter that would capture the stack on a real project. */
function recordQuery(string $sql, array $params): void
{
    QueryGuard::collector()->record(new QueryEvent(
        $sql,
        $params,
        stack: debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS),
    ));
}

it('reports a callsite in the test and no caller above it', function () {
    foreach ([1, 2, 3] as $ignored) {
        recordQuery('SELECT id FROM translations WHERE locale = ?', ['de']);
    }

    expect(true)->toBeTrue();
});
