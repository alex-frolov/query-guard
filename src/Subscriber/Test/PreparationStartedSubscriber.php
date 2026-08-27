<?php

declare(strict_types=1);

namespace QueryGuard\Subscriber\Test;

use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber as PHPUnitPreparationStartedSubscriber;
use QueryGuard\Adapter\AdapterSet;
use QueryGuard\Collector\DefaultQueryCollector;

/**
 * Opens the "fixture bucket": everything that reaches the database before
 * `Test\Prepared` belongs to `setUp()`.
 *
 * Adapters are also reinstalled here: Laravel rebuilds the application for every test,
 * and a `DB::listen` listener disappears along with the old one. For Doctrine the call
 * does nothing — adapters hook in at different moments, and that is their own business.
 */
final class PreparationStartedSubscriber implements PHPUnitPreparationStartedSubscriber
{
    public function __construct(
        private readonly DefaultQueryCollector $collector,
        private readonly AdapterSet $adapters,
    ) {
    }

    public function notify(PreparationStarted $event): void
    {
        $this->collector->beginFixtures();
        $this->adapters->install($this->collector);
    }
}
