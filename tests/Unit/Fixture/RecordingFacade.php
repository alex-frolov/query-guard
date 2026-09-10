<?php

declare(strict_types=1);

namespace QueryGuard\Test\Unit\Fixture;

use PHPUnit\Event\Subscriber;
use PHPUnit\Event\Tracer\Tracer;
use PHPUnit\Runner\Extension\Facade;

/**
 * Keeps the subscribers an extension registers instead of handing them to PHPUnit.
 *
 * Only loadable on PHPUnit 13 and up, where `Facade` became an interface; before that it
 * is a final class writing straight into the event system, which is sealed by the time
 * any test runs. `ExtensionTest` guards the reference to this class accordingly.
 */
final class RecordingFacade implements Facade
{
    /** @var list<Subscriber> */
    public array $subscribers = [];

    public function registerSubscribers(Subscriber ...$subscribers): void
    {
        foreach ($subscribers as $subscriber) {
            $this->subscribers[] = $subscriber;
        }
    }

    public function registerSubscriber(Subscriber $subscriber): void
    {
        $this->subscribers[] = $subscriber;
    }

    public function registerTracer(Tracer $tracer): void
    {
    }

    public function replaceOutput(): void
    {
    }

    public function replaceProgressOutput(): void
    {
    }

    public function replaceResultOutput(): void
    {
    }

    public function requireCodeCoverageCollection(): void
    {
    }
}
