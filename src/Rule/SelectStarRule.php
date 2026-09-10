<?php

declare(strict_types=1);

namespace QueryGuard\Rule;

use QueryGuard\Finding\Finding;
use QueryGuard\Finding\Severity;
use QueryGuard\Query\CallsiteResolver;
use QueryGuard\Query\Trace;

/**
 * `SELECT *` — extra columns over the wire and past covering indexes.
 *
 * **Off by default, and that is deliberate.** `select *` is Eloquent's default mode, so
 * on any Laravel project this rule would fire on every single query and drown out
 * everything else. Turn it on knowingly, with the `select-star` parameter.
 */
final class SelectStarRule implements Rule
{
    public function __construct(
        private readonly CallsiteResolver $callsiteResolver,
        private readonly bool $enabled = false,
    ) {
    }

    public function id(): string
    {
        return 'select-star';
    }

    public function check(Trace $trace): iterable
    {
        if (!$this->enabled) {
            return;
        }

        $seen = [];

        foreach ($trace->events() as $event) {
            if (!Sql::isSelectStar($event->sql)) {
                continue;
            }

            $callsite = $event->callsite($this->callsiteResolver);
            $signature = Finding::signature($this->id(), $callsite, $event->fingerprint()->value());

            if (isset($seen[$signature])) {
                continue;
            }

            $seen[$signature] = true;

            yield new Finding(
                rule: $this->id(),
                test: $trace->test,
                message: 'SELECT * — every column is fetched: '.Sql::shorten($event->sql),
                severity: Severity::Info,
                callsite: $callsite,
                signature: $signature,
            );
        }
    }
}
