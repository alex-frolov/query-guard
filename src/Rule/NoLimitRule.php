<?php

declare(strict_types=1);

namespace QueryGuard\Rule;

use QueryGuard\Finding\Finding;
use QueryGuard\Finding\Severity;
use QueryGuard\Query\CallsiteResolver;
use QueryGuard\Query\Trace;

/**
 * A `SELECT` without a `LIMIT` against a table known to be large.
 *
 * The table list is given by hand (`large-tables`), and without it the rule stays
 * silent. Detecting size from statistics belongs to tier 2 and a reference database:
 * on a three-row test database "a large table" cannot be determined at all.
 */
final class NoLimitRule implements Rule
{
    /**
     * @param list<string> $largeTables
     */
    public function __construct(
        private readonly CallsiteResolver $callsiteResolver,
        private readonly array $largeTables = [],
    ) {
    }

    public function id(): string
    {
        return 'no-limit';
    }

    public function check(Trace $trace): iterable
    {
        if ([] === $this->largeTables) {
            return;
        }

        $seen = [];

        foreach ($trace->events() as $event) {
            if (!$event->isSelect() || Sql::hasLimit($event->sql)) {
                continue;
            }

            $table = $this->matchedTable($event->sql);

            if (null === $table) {
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
                message: sprintf('SELECT without LIMIT against the large table "%s": %s', $table, Sql::shorten($event->sql)),
                severity: Severity::Warning,
                callsite: $callsite,
                signature: $signature,
            );
        }
    }

    private function matchedTable(string $sql): ?string
    {
        foreach ($this->largeTables as $table) {
            if (Sql::touchesTable($sql, $table)) {
                return $table;
            }
        }

        return null;
    }
}
