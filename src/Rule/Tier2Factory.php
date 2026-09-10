<?php

declare(strict_types=1);

namespace QueryGuard\Rule;

use QueryGuard\Adapter\AdapterSet;
use QueryGuard\Collector\DefaultQueryCollector;
use QueryGuard\Platform\PlanProvider;
use QueryGuard\Query\CallsiteResolver;

/**
 * Builds the tier 2 rules and reports, at the end of the run, what tier 2 was able to
 * look at.
 *
 * **The rules no longer wait for a connection.** They used to: a single explainer had to
 * be picked before a `PlanProvider` could exist, and none was available when the
 * extension loaded. That resolution also happened exactly once, so whichever connection
 * was open at the first test decided tier 2 for the whole run — a secondary SQLite
 * connection could switch off plan rules on a MySQL project, and the notice blamed the
 * platform rather than the choice. `PlanProvider` now resolves a connection at the moment
 * it first sees a query from it, so there is nothing left to defer.
 *
 * What can only be known at the end is which platforms actually answered, and that is
 * what `notices()` is for.
 */
final class Tier2Factory
{
    private readonly PlanProvider $provider;

    public function __construct(
        AdapterSet $adapters,
        DefaultQueryCollector $collector,
        private readonly CallsiteResolver $callsiteResolver,
        private readonly int $minRows = PlanRule::DEFAULT_MIN_ROWS,
    ) {
        $this->provider = new PlanProvider($adapters, $collector);
    }

    /**
     * Exposed so an adapter that cannot wait for a rule to ask — Eloquent, see
     * `EloquentAdapter::enableEagerExplain()` — can call `planFor()` itself, right where
     * the query fires.
     */
    public function provider(): PlanProvider
    {
        return $this->provider;
    }

    /**
     * @return list<Rule>
     */
    public function rules(): array
    {
        return [
            new NoPossibleIndexRule($this->provider, $this->callsiteResolver, $this->minRows),
            new TableScanRule($this->provider, $this->callsiteResolver, $this->minRows),
            new FilesortRule($this->provider, $this->callsiteResolver, $this->minRows),
            new TemporaryTableRule($this->provider, $this->callsiteResolver, $this->minRows),
        ];
    }

    /**
     * @return list<string>
     */
    public function notices(): array
    {
        $notices = [];

        foreach ($this->provider->unsupportedConnections() as $connection => $platform) {
            $notices[] = sprintf(
                "the \"%s\" connection runs on \"%s\", which tier 2 does not support — its queries were not explained.\n"
                .'Supported: MySQL/MariaDB and PostgreSQL. Other connections are unaffected.',
                $connection,
                $platform,
            );
        }

        if (!$this->provider->sawAnyConnection()) {
            $notices[] = [] === $this->provider->unsupportedConnections()
                ? 'tier 2 is enabled but no database connection appeared during the run — no plans were looked at.'
                : 'tier 2 is enabled but every connection it saw was on an unsupported platform — no plans were looked at.';

            return $notices;
        }

        foreach ($this->provider->driversUsed() as $driver) {
            if (!$driver->reportsPossibleIndexes()) {
                $notices[] = sprintf(
                    'the no-possible-index rule does not work on "%s": the platform does not report '
                    .'which indexes could have applied. This is not "all clear" — the rule simply cannot judge.',
                    $driver->name(),
                );
            }

            if (!$driver->reportsTemporaryTable()) {
                $notices[] = sprintf(
                    'the temporary-table rule does not work on "%s": there is no notion of a '
                    .'"temporary table" in the MySQL sense there.',
                    $driver->name(),
                );
            }
        }

        $notices[] = sprintf(
            'tier 2: %d plans parsed, %d failed.',
            $this->provider->explained(),
            $this->provider->failed(),
        );

        return $notices;
    }
}
