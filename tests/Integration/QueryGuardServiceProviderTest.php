<?php

declare(strict_types=1);

namespace QueryGuard\Test\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Events\Dispatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use QueryGuard\Adapter\Eloquent\EloquentAdapter;
use QueryGuard\Adapter\Eloquent\QueryGuardServiceProvider;
use QueryGuard\Collector\DefaultQueryCollector;
use QueryGuard\Query\Trace;
use QueryGuard\QueryGuard;
use QueryGuard\TestIdentifier;
use QueryGuard\TestOptions;

/**
 * The only subscription path that can see a query made inside `setUp()`: Laravel boots
 * the provider along with the application, which happens after `Test\PreparationStarted`
 * and before anything the extension could do about it.
 */
#[CoversClass(QueryGuardServiceProvider::class)]
final class QueryGuardServiceProviderTest extends TestCase
{
    private Container $container;

    private Dispatcher $dispatcher;

    protected function setUp(): void
    {
        EloquentAdapter::reset();

        $this->container = new Container();
        $this->dispatcher = new Dispatcher($this->container);
        $this->container->instance('events', $this->dispatcher);
    }

    protected function tearDown(): void
    {
        EloquentAdapter::reset();
        QueryGuard::deactivate();
    }

    /**
     * The provider is handed no collector, so the listener has to find the active one
     * through `QueryGuard` — the same route a DBAL middleware takes.
     */
    public function testBootedProviderSendsQueriesToTheActiveCollector(): void
    {
        (new QueryGuardServiceProvider($this->container))->boot();

        $collector = new DefaultQueryCollector();
        QueryGuard::activate($collector);
        $collector->beginFixtures();
        $trace = $collector->beginTrace(new TestIdentifier('id', 'SomeTest::testSomething'), TestOptions::none());

        $this->capsule()->getConnection()->statement('CREATE TABLE t (id INTEGER)');

        self::assertInstanceOf(Trace::class, $trace);
        self::assertSame(1, $trace->count());
        self::assertTrue((new EloquentAdapter())->isInstalled());
    }

    /**
     * Outside a PHPUnit run the listener reaches the null collector and does nothing —
     * a provider Laravel boots on every request must not cost anything there.
     */
    public function testWithoutAnActiveCollectorNothingIsRecorded(): void
    {
        (new QueryGuardServiceProvider($this->container))->boot();

        $this->capsule()->getConnection()->statement('CREATE TABLE t (id INTEGER)');

        self::assertFalse(QueryGuard::isActive());
        self::assertFalse(QueryGuard::collector()->isRecording());
    }

    public function testAnApplicationWithoutAnEventDispatcherIsLeftAlone(): void
    {
        (new QueryGuardServiceProvider(new Container()))->boot();

        self::assertFalse((new EloquentAdapter())->isInstalled());
    }

    /**
     * `events` is resolved out of the container, and a container can be made to answer
     * with anything at all.
     */
    public function testSomethingOtherThanADispatcherIsNotSubscribedTo(): void
    {
        $container = new Container();
        $container->bind('events', static fn (): string => 'not a dispatcher');

        (new QueryGuardServiceProvider($container))->boot();

        self::assertFalse((new EloquentAdapter())->isInstalled());
    }

    private function capsule(): Manager
    {
        $capsule = new Manager($this->container);
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setEventDispatcher($this->dispatcher);
        $capsule->bootEloquent();

        return $capsule;
    }
}
