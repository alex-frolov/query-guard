<?php

declare(strict_types=1);

namespace QueryGuard\Test\Unit\Fixture;

use PHPUnit\Event\EventFacadeIsSealedException;
use PHPUnit\Event\Subscriber;
use PHPUnit\Event\Tracer\Tracer;
use PHPUnit\Runner\Extension\Facade;

/**
 * An extension facade whose event system is already sealed.
 *
 * That is not a hypothetical: ParaTest's parent process bootstraps the configured
 * extensions from `SuiteLoader`, and under Pest that runs after `WrapperRunner::run()` has
 * sealed PHPUnit's event system. Every extension that registers a subscriber there gets
 * this exception, PHPUnit records it as a test-runner warning, and Pest fails the run over
 * it — a green suite with exit code 1 and nothing in the output to explain it.
 *
 * Only loadable on PHPUnit 13 and up, where `Facade` became an interface; `ExtensionTest`
 * guards the reference accordingly.
 */
final class SealedFacade implements Facade
{
    public function registerSubscribers(Subscriber ...$subscribers): void
    {
        throw new EventFacadeIsSealedException();
    }

    public function registerSubscriber(Subscriber $subscriber): void
    {
        throw new EventFacadeIsSealedException();
    }

    public function registerTracer(Tracer $tracer): void
    {
        throw new EventFacadeIsSealedException();
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
