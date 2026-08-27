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
        self::assertCount(5, reset($groups));
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

    public function testNothingIsRecordedOutsideTestBoundaries(): void
    {
        (new EloquentAdapter($this->capsule->getConnection()))->install($this->collector);

        $this->collector->endTrace();
        $this->capsule->getConnection()->statement('CREATE TABLE t (id INTEGER)');

        self::assertSame(0, $this->collector->totalRecorded());
    }
}
