<?php

declare(strict_types=1);

namespace QueryGuard\Query;

/**
 * The place in the application code a query left from — and, optionally, who called it.
 *
 * `file:line` is the first application frame: for lazy loading that is the moment the
 * collection was touched, which is what needs fixing. It is not always what needs
 * *reading*. A getter is shared by every caller, so the callsite is the same line
 * whichever of them is at fault, and the one thing the finding does not say is which —
 * leaving it to be guessed from the code around it, where a plausible caller and the
 * actual one are easy to confuse. On Eloquent that is worse than occasionally awkward:
 * on one real suite 4 497 findings pointed at a single magic accessor every association
 * goes through.
 *
 * Hence `$chain`: the next few application frames above the callsite, already formatted
 * as `file:line Class::method`. Strings rather than frames — a frame holds objects, and
 * `QueryEvent` explains what keeping those costs (~106 MB per 1000 queries). Identical
 * chains are shared, which they mostly are: the same path repeats for every query in an
 * N+1.
 */
final readonly class Callsite implements \Stringable
{
    /**
     * @param list<string> $chain application frames above this one, nearest first
     */
    public function __construct(
        public string $file,
        public int $line,
        public ?string $function = null,
        public array $chain = [],
    ) {
    }

    public function __toString(): string
    {
        return $this->file.':'.$this->line;
    }
}
