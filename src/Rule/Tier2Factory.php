<?php

declare(strict_types=1);

namespace QueryGuard\Rule;

use QueryGuard\Adapter\AdapterSet;
use QueryGuard\Collector\DefaultQueryCollector;
use QueryGuard\Platform\PlanProvider;
use QueryGuard\Platform\PlatformDrivers;
use QueryGuard\Query\CallsiteResolver;

/**
 * Builds the tier 2 rules once a connection exists.
 *
 * It cannot happen earlier: `EXPLAIN` has to run on the same connection as the query,
 * and no connection exists by the time the extension loads.
 */
final class Tier2Factory
{
    private ?PlanProvider $provider = null;

    /** @var list<string> */
    private array $notices = [];

    private bool $resolved = false;

    public function __construct(
        private readonly AdapterSet $adapters,
        private readonly DefaultQueryCollector $collector,
        private readonly CallsiteResolver $callsiteResolver,
        private readonly int $minRows = PlanRule::DEFAULT_MIN_ROWS,
    ) {
    }

    /**
     * @return list<Rule>
     */
    public function rules(): array
    {
        if ($this->resolved) {
            return [];
        }

        $explainer = $this->adapters->explainer();

        if (null === $explainer) {
            // no connection yet — try again on the next test
            return [];
        }

        $this->resolved = true;

        $driver = PlatformDrivers::for($explainer->platform());

        if (null === $driver) {
            $this->notices[] = sprintf(
                "tier 2 is enabled but the \"%s\" platform is not supported — no plan rules ran.\n"
                .'Supported: MySQL/MariaDB and PostgreSQL.',
                $explainer->platform(),
            );

            return [];
        }

        $this->provider = new PlanProvider($explainer, $driver, $this->collector);

        if (!$driver->reportsPossibleIndexes()) {
            $this->notices[] = sprintf(
                'the no-possible-index rule does not work on "%s": the platform does not report '
                .'which indexes could have applied. This is not "all clear" — the rule simply cannot judge.',
                $driver->name(),
            );
        }

        if (!$driver->reportsTemporaryTable()) {
            $this->notices[] = sprintf(
                'the temporary-table rule does not work on "%s": there is no notion of a '
                .'"temporary table" in the MySQL sense there.',
                $driver->name(),
            );
        }

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
        $notices = $this->notices;

        if (null !== $this->provider) {
            $notices[] = sprintf(
                'tier 2: %d plans parsed, %d failed.',
                $this->provider->explained(),
                $this->provider->failed(),
            );
        } elseif (false === $this->resolved) {
            $notices[] = 'tier 2 is enabled but no database connection appeared during the run — no plans were looked at.';
        }

        return $notices;
    }
}
