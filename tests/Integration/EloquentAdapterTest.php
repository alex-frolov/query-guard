<?php

declare(strict_types=1);

namespace QueryGuard\Test\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Events\Dispatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use QueryGuard\Adapter\Eloquent\EloquentAdapter;
use QueryGuard\Collector\DefaultQueryCollector;
use QueryGuard\Query\CallsiteResolver;
use QueryGuard\Query\QueryEvent;
use QueryGuard\Query\Trace;
use QueryGuard\TestIdentifier;
use QueryGuard\TestOptions;

/**
 * Real Eloquent over an in-memory sqlite database.
 *
 * A stub with no enrichment — but the important part is covered: the events reach the
 * same seam as Doctrine's, and the core works on them without knowing which ORM sent them.
 */
#[CoversClass(EloquentAdapter::class)]
final class EloquentAdapterTest extends TestCase
{
    private DefaultQueryCollector $collector;

    private Trace $trace;

    private Manager $capsule;

    protected function setUp(): void
    {
        EloquentAdapter::reset();

        $this->capsule = new Manager();
        $this->capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->capsule->setEventDispatcher(new Dispatcher(new Container()));
        $this->capsule->bootEloquent();

        $this->collector = new DefaultQueryCollector();
        $this->collector->beginFixtures();
        $this->trace = $this->collector->beginTrace(
            new TestIdentifier('id', 'EloquentAdapterTest::test'),
            TestOptions::none(),
        );
    }

    protected function tearDown(): void
    {
        EloquentAdapter::reset();
    }

    public function testQueriesAreRecordedWithSqlBindingsAndDuration(): void
    {
        $adapter = new EloquentAdapter($this->capsule->getConnection());
        $adapter->install($this->collector);

        self::assertTrue(EloquentAdapter::supports());
        self::assertTrue($adapter->isInstalled());

        $connection = $this->capsule->getConnection();
        $connection->statement('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
        $connection->insert('INSERT INTO users (id, name) VALUES (?, ?)', [1, 'Ada']);
        $connection->select('SELECT name FROM users WHERE id = ?', [1]);

        $events = $this->trace->events();
        self::assertCount(3, $events);

        $select = $events[2];
        self::assertSame('SELECT name FROM users WHERE id = ?', $select->sql);
        self::assertSame([1], $select->params);
        self::assertNotNull($select->durationMs);
        self::assertSame('default', $select->connection);
    }

    public function testNPlusOneShapeCollapsesIntoOneFingerprint(): void
    {
        (new EloquentAdapter($this->capsule->getConnection()))->install($this->collector);

        $connection = $this->capsule->getConnection();
        $connection->statement('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');

        for ($id = 1; $id <= 5; ++$id) {
            $connection->insert('INSERT INTO users (id, name) VALUES (?, ?)', [$id, 'user '.$id]);
        }

        $this->collector->beginTrace(new TestIdentifier('id', 'reset'), TestOptions::none());

        for ($id = 1; $id <= 5; ++$id) {
            $connection->select('SELECT name FROM users WHERE id = ?', [$id]);
        }

        $trace = $this->collector->endTrace();
        self::assertNotNull($trace);

        $groups = $trace->groupedByFingerprint();

        self::assertCount(1, $groups);
        self::assertCount(5, array_values($groups)[0]);
    }

    public function testCallsitePointsAtApplicationCode(): void
    {
        (new EloquentAdapter($this->capsule->getConnection()))->install($this->collector);

        $this->capsule->getConnection()->statement('CREATE TABLE t (id INTEGER)');

        $callsite = $this->trace->events()[0]->callsite(CallsiteResolver::default());

        self::assertNotNull($callsite);
        self::assertStringEndsWith('EloquentAdapterTest.php', $callsite->file);
    }

    /**
     * Laravel rebuilds the application for every test, so `install()` runs before each
     * of them — and must not pile up listeners.
     */
    public function testRepeatedInstallDoesNotDuplicateEvents(): void
    {
        $adapter = new EloquentAdapter($this->capsule->getConnection());

        $adapter->install($this->collector);
        $adapter->install($this->collector);
        $adapter->install($this->collector);

        $this->capsule->getConnection()->statement('CREATE TABLE t (id INTEGER)');

        self::assertSame(1, $this->trace->count());
    }

    /**
     * Subscribing to the dispatcher covers every connection at once — the path
     * `QueryGuardServiceProvider` takes in a live Laravel application.
     */
    public function testAttachingToTheEventDispatcherCoversConnections(): void
    {
        $dispatcher = $this->capsule->getEventDispatcher();
        self::assertNotNull($dispatcher);
        self::assertTrue(EloquentAdapter::attach($dispatcher, $this->collector));

        $this->capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:'], 'second');
        $this->capsule->getConnection('second')->statement('CREATE TABLE t (id INTEGER)');

        self::assertSame(1, $this->trace->count());
        self::assertSame('second', $this->trace->events()[0]->connection);
    }

    /**
     * `DatabaseManager` has no `listen()` method at all: `DB::listen()` goes through
     * `__call` and only ever reaches the default connection. That is exactly why the
     * first version of this adapter failed to hook into a live Laravel application.
     */
    public function testDatabaseManagerHasNoRealListenMethod(): void
    {
        self::assertFalse(method_exists(\Illuminate\Database\DatabaseManager::class, 'listen'));
        self::assertTrue(method_exists(\Illuminate\Database\Connection::class, 'listen'));
    }

    /**
     * A subscription that reaches one connection is not a subscription that worked.
     *
     * It succeeds, reports itself installed, and misses every secondary connection —
     * which is the exact shape of failure this package exists to refuse. So it has to
     * appear in the summary next to the findings, where the run still looks healthy.
     */
    public function testAConnectionOnlyListenerSaysSoInTheSummary(): void
    {
        $adapter = new EloquentAdapter($this->capsule->getConnection());
        $adapter->install($this->collector);

        self::assertTrue($adapter->isInstalled());
        self::assertStringContainsString(
            'the listener sits on a single connection',
            implode("\n", $adapter->notices()),
        );
    }

    /**
     * The dispatcher covers every connection, so nothing needs saying.
     */
    public function testADispatcherSubscriptionIsNotComplainedAbout(): void
    {
        $dispatcher = $this->capsule->getEventDispatcher();
        self::assertNotNull($dispatcher);

        $adapter = new EloquentAdapter($dispatcher);
        $adapter->install($this->collector);

        self::assertTrue($adapter->isInstalled());
        self::assertSame([], $adapter->notices());
    }

    /**
     * The first `install()` call can land before the application (and its dispatcher)
     * exists — see the class docblock's three subscription paths — and falls back to a
     * single connection. Once a later call reaches the dispatcher, the run has full
     * coverage again, and the stale "single connection" notice must not survive it.
     */
    public function testReachingTheDispatcherLaterClearsTheSingleConnectionNotice(): void
    {
        $adapter = new EloquentAdapter($this->capsule->getConnection());
        $adapter->install($this->collector);
        self::assertNotSame([], $adapter->notices());

        $dispatcher = $this->capsule->getEventDispatcher();
        self::assertNotNull($dispatcher);
        self::assertTrue(EloquentAdapter::attach($dispatcher, $this->collector));

        self::assertSame([], $adapter->notices());
    }

    public function testNothingIsRecordedOutsideTestBoundaries(): void
    {
        (new EloquentAdapter($this->capsule->getConnection()))->install($this->collector);

        $this->collector->endTrace();
        $this->capsule->getConnection()->statement('CREATE TABLE t (id INTEGER)');

        self::assertSame(0, $this->collector->totalRecorded());
    }

    public function testExplainersAreBuiltFromConnectionsSeen(): void
    {
        (new EloquentAdapter($this->capsule->getConnection()))->install($this->collector);
        $this->capsule->getConnection()->statement('CREATE TABLE t (id INTEGER)');

        $explainers = (new EloquentAdapter())->explainers();

        self::assertArrayHasKey('default', $explainers);
        self::assertSame('sqlite', $explainers['default']->platform());
    }

    /**
     * `PlanProvider` resolves an `Explainer` once per connection name and keeps it for
     * the rest of the run — safe for Doctrine's single suite-wide connection, not for
     * Eloquent, which gets a brand new `Connection` every test. An `Explainer` bound to
     * whichever connection was live when it was built would EXPLAIN a later test's query
     * through a torn-down one. Confirmed missing on a live Laravel run before this fix: a
     * fingerprint first seen in a later test failed with `Target class [config] does not
     * exist` — the reconnect callback of an already-destroyed application's container.
     */
    public function testExplainerReflectsTheCurrentConnectionNotTheFirstOne(): void
    {
        (new EloquentAdapter($this->capsule->getConnection()))->install($this->collector);
        $this->capsule->getConnection()->statement('CREATE TABLE t (id INTEGER)');

        $explainer = (new EloquentAdapter())->explainers()['default'];

        // stands in for the next test: a fresh application, a fresh connection, same name
        $secondApp = new Manager();
        $secondApp->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $secondApp->setEventDispatcher(new Dispatcher(new Container()));
        $secondApp->bootEloquent();
        (new EloquentAdapter($secondApp->getConnection()))->install($this->collector);
        $secondApp->getConnection()->statement('CREATE TABLE u (id INTEGER)');
        $secondApp->getConnection()->insert('INSERT INTO u (id) VALUES (7)');

        // "u" only exists on the second connection — resolving the stale first one
        // would fail outright instead of returning this row
        self::assertSame([['id' => 7]], $explainer->run('SELECT id FROM u'));
    }

    public function testEagerExplainRunsSynchronouslyOnEverySelect(): void
    {
        $seen = [];
        EloquentAdapter::enableEagerExplain(function (QueryEvent $event) use (&$seen): void {
            $seen[] = $event->sql;
        });

        (new EloquentAdapter($this->capsule->getConnection()))->install($this->collector);
        $connection = $this->capsule->getConnection();
        $connection->statement('CREATE TABLE t (id INTEGER)');
        $connection->select('SELECT * FROM t');

        self::assertSame(['SELECT * FROM t'], $seen);
    }

    public function testEagerExplainIsNotCalledForWrites(): void
    {
        $seen = [];
        EloquentAdapter::enableEagerExplain(function (QueryEvent $event) use (&$seen): void {
            $seen[] = $event->sql;
        });

        (new EloquentAdapter($this->capsule->getConnection()))->install($this->collector);
        $connection = $this->capsule->getConnection();
        $connection->statement('CREATE TABLE t (id INTEGER)');
        $connection->insert('INSERT INTO t (id) VALUES (1)');

        self::assertSame([], $seen);
    }

    /**
     * The eager hook goes back through the same connection — a real EXPLAIN does exactly
     * this. Recursing into the same listener must not record that query a second time or
     * call the hook on it, the way `PlanProvider::explain()` guards it in production by
     * pausing the collector around the call.
     */
    public function testEagerExplainRecursingThroughTheSameConnectionDoesNotLoop(): void
    {
        $calls = 0;
        EloquentAdapter::enableEagerExplain(function (QueryEvent $event) use (&$calls): void {
            ++$calls;

            $this->collector->pause();

            try {
                $this->capsule->getConnection()->select('SELECT 1');
            } finally {
                $this->collector->resume();
            }
        });

        (new EloquentAdapter($this->capsule->getConnection()))->install($this->collector);
        $connection = $this->capsule->getConnection();
        $connection->statement('CREATE TABLE t (id INTEGER)');
        $connection->select('SELECT * FROM t');

        self::assertSame(1, $calls);
        self::assertCount(2, $this->trace->events());
    }

    /**
     * A hook left over from a previous test must not fire in the next one — `reset()` is
     * called from every test's `tearDown()` for exactly this reason.
     */
    public function testResetClearsTheEagerExplainHook(): void
    {
        $calls = 0;
        EloquentAdapter::enableEagerExplain(function () use (&$calls): void {
            ++$calls;
        });

        EloquentAdapter::reset();

        (new EloquentAdapter($this->capsule->getConnection()))->install($this->collector);
        $connection = $this->capsule->getConnection();
        $connection->statement('CREATE TABLE t (id INTEGER)');
        $connection->select('SELECT * FROM t');

        self::assertSame(0, $calls);
    }
}
