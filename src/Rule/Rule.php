<?php

declare(strict_types=1);

namespace QueryGuard\Rule;

use QueryGuard\Finding\Finding;
use QueryGuard\Query\Trace;

/**
 * Rules only ever see `Trace`, `QueryEvent` and — in tier 2 — `Plan`, never a Doctrine
 * or Illuminate class. That is what makes them portable to a second ORM.
 */
interface Rule
{
    /**
     * Identifier used by the baseline, the `#[IgnoreRule]` attribute and the summary.
     */
    public function id(): string;

    /**
     * @return iterable<Finding>
     */
    public function check(Trace $trace): iterable;
}
