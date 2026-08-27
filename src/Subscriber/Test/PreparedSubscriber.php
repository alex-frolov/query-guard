<?php

declare(strict_types=1);

namespace QueryGuard\Subscriber\Test;

use PHPUnit\Event\Code\TestMethod;
use PHPUnit\Event\Test\Prepared;
use PHPUnit\Event\Test\PreparedSubscriber as PHPUnitPreparedSubscriber;
use QueryGuard\Adapter\AdapterSet;
use QueryGuard\Collector\DefaultQueryCollector;
use QueryGuard\TestIdentifier;
use QueryGuard\TestOptions;

/**
 * Opens the trace for a test — after `setUp()`, not before it.
 *
 * This is the decision that false positives hinge on: a factory creating 50 entities in
 * `setUp()` produces 50 identical INSERTs from one callsite, i.e. a perfect false N+1.
 */
final class PreparedSubscriber implements PHPUnitPreparedSubscriber
{
    public function __construct(
        private readonly DefaultQueryCollector $collector,
        private readonly AdapterSet $adapters,
    ) {
    }

    public function notify(Prepared $event): void
    {
        $test = $event->test();

        $className = $test instanceof TestMethod ? $test->className() : null;
        $methodName = $test instanceof TestMethod ? $test->methodName() : null;

        // fallback installation: Laravel creates the application inside `setUp()`, i.e.
        // after `PreparationStarted` — before that moment there is nothing to subscribe to
        $this->adapters->install($this->collector);

        $this->collector->beginTrace(
            new TestIdentifier(
                id: $test->id(),
                label: $test instanceof TestMethod ? $test->nameWithClass() : $test->name(),
                className: $className,
                methodName: $methodName,
            ),
            TestOptions::fromTest($className, $methodName),
        );
    }
}
