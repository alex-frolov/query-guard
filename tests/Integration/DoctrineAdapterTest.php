<?php

declare(strict_types=1);

namespace QueryGuard\Test\Integration;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use QueryGuard\Adapter\Doctrine\Dbal;
use QueryGuard\Adapter\Doctrine\DoctrineAdapter;
use QueryGuard\Adapter\Doctrine\Driver;
use QueryGuard\Adapter\Doctrine\Middleware;
use QueryGuard\Adapter\Doctrine\Recorder;
use QueryGuard\Collector\DefaultQueryCollector;
use QueryGuard\Query\CallsiteResolver;
use QueryGuard\Query\Trace;
use QueryGuard\QueryGuard;
use QueryGuard\TestIdentifier;
use QueryGuard\TestOptions;

/**
 * Real DBAL over an in-memory sqlite database. A mock would be useless here: what is
 * being checked is exactly that the Driver → Connection → Statement decorator is
 * compatible with a live DBAL.
 */
#[CoversClass(Middleware::class)]
#[CoversClass(Driver::class)]
#[CoversClass(Recorder::class)]
#[CoversClass(DoctrineAdapter::class)]
final class DoctrineAdapterTest extends TestCase
{
    private DefaultQueryCollector $collector;

    private Trace $trace;

    protected function setUp(): void
    {
        DoctrineAdapter::reset();

        $this->collector = new DefaultQueryCollector();
        QueryGuard::activate($this->collector);

        $this->collector->beginFixtures();
        $this->trace = $this->collector->beginTrace(
            new TestIdentifier('id', 'DoctrineAdapterTest::test'),
            TestOptions::none(),
        );
    }

    protected function tearDown(): void
    {
        QueryGuard::deactivate();
        DoctrineAdapter::reset();

        foreach (['primary', 'analytics'] as $name) {
            @unlink($this->file($name));
        }
    }

    public function testPreparedStatementIsRecordedWithSqlBindingsAndDuration(): void
    {
        $connection = $this->connect();
        $connection->executeStatement('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
        $connection->executeStatement('INSERT INTO users (id, name) VALUES (?, ?)', [1, 'Ada']);

        $this->collector->beginTrace(new TestIdentifier('id', 'reset'), TestOptions::none());

        $connection->fetchAssociative('SELECT name FROM users WHERE id = ?', [1]);

        $trace = $this->collector->endTrace();
        self::assertNotNull($trace);
        self::assertSame(1, $trace->count());

        $event = $trace->events()[0];
        self::assertSame('SELECT name FROM users WHERE id = ?', $event->sql);
        self::assertSame([1 => 1], $event->params);
        self::assertNotNull($event->durationMs);
        self::assertGreaterThan(0.0, $event->durationMs);
    }

    /**
     * Doctrine's own `Logging\Middleware` records a query BEFORE it runs and gives no
     * duration at all — which is why a competitor's `assertMaxQueryTime(0.001)` passes
     * on six real queries.
     */
    public function testEveryStatementCarriesTiming(): void
    {
        $connection = $this->connect();
        $connection->executeStatement('CREATE TABLE t (id INTEGER)');
        $connection->executeQuery('SELECT * FROM t');

        foreach ($this->trace->events() as $event) {
            self::assertNotNull($event->durationMs, $event->sql);
        }
    }

    public function testNPlusOneShapeCollapsesIntoOneFingerprint(): void
    {
        $connection = $this->connect();
        $connection->executeStatement('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');

        for ($id = 1; $id <= 5; ++$id) {
            $connection->executeStatement('INSERT INTO users (id, name) VALUES (?, ?)', [$id, 'user '.$id]);
        }

        $this->collector->beginTrace(new TestIdentifier('id', 'reset'), TestOptions::none());

        for ($id = 1; $id <= 5; ++$id) {
            $connection->fetchAssociative('SELECT name FROM users WHERE id = ?', [$id]);
        }

        $trace = $this->collector->endTrace();
        self::assertNotNull($trace);

        $groups = $trace->groupedByFingerprint();

        self::assertCount(1, $groups, 'five fetches with different values — one fingerprint');
        self::assertCount(5, array_values($groups)[0]);
    }

    public function testCallsitePointsAtApplicationCodeNotAtTheAdapter(): void
    {
        $connection = $this->connect();
        $connection->executeStatement('CREATE TABLE t (id INTEGER)');

        $event = $this->trace->events()[0];
        $callsite = $event->callsite(CallsiteResolver::default());

        self::assertNotNull($callsite);
        self::assertStringEndsWith('DoctrineAdapterTest.php', $callsite->file);
    }

    public function testNothingIsRecordedOutsideTestBoundaries(): void
    {
        $connection = $this->connect();
        $this->collector->endTrace();

        $connection->executeStatement('CREATE TABLE t (id INTEGER)');

        self::assertSame(0, $this->collector->totalRecorded());
    }

    public function testAdapterReportsItselfInstalledOnlyAfterAConnection(): void
    {
        $adapter = new DoctrineAdapter();

        self::assertTrue(DoctrineAdapter::supports());
        self::assertFalse($adapter->isInstalled());

        $this->connect()->executeQuery('SELECT 1');

        self::assertTrue($adapter->isInstalled());
    }

    public function testBothDbalGenerationsAreCovered(): void
    {
        self::assertSame(
            Dbal::isVersion4(),
            (new \ReflectionClass(\Doctrine\DBAL\ParameterType::class))->isEnum(),
        );
    }

    /**
     * Every connection is registered under its own name, and each keeps its own platform.
     *
     * A single static used to hold whichever connected last, so on a project with two
     * databases tier 2 explained one query against the other — and parsed the plan with
     * the other's platform driver. Both connections have to survive, in full.
     */
    public function testEveryConnectionIsRegisteredUnderItsOwnName(): void
    {
        $primary = $this->connectTo(['driver' => 'pdo_sqlite', 'path' => $this->file('primary')]);
        $analytics = $this->connectTo(['driver' => 'pdo_sqlite', 'path' => $this->file('analytics')]);

        // DBAL connects lazily: until a query runs there is nothing to register
        $primary->fetchOne('SELECT 1');
        $analytics->fetchOne('SELECT 1');

        $explainers = (new DoctrineAdapter())->explainers();

        self::assertSame(
            [$this->file('primary'), $this->file('analytics')],
            array_keys($explainers),
        );

        foreach ($explainers as $explainer) {
            self::assertSame('sqlite', $explainer->platform());
        }
    }

    /**
     * The connection a query came from is recorded on the event, which is what lets tier 2
     * send its EXPLAIN back to the right database.
     */
    public function testAQueryCarriesTheConnectionItRanOn(): void
    {
        $primary = $this->connectTo(['driver' => 'pdo_sqlite', 'path' => $this->file('primary')]);
        $analytics = $this->connectTo(['driver' => 'pdo_sqlite', 'path' => $this->file('analytics')]);

        $primary->executeStatement('CREATE TABLE t (id INTEGER PRIMARY KEY)');
        $analytics->executeStatement('CREATE TABLE t (id INTEGER PRIMARY KEY)');

        $this->collector->beginTrace(new TestIdentifier('id', 'reset'), TestOptions::none());

        $primary->fetchAllAssociative('SELECT id FROM t');
        $analytics->fetchAllAssociative('SELECT id FROM t');

        $trace = $this->collector->endTrace();
        self::assertNotNull($trace);

        self::assertSame(
            [$this->file('primary'), $this->file('analytics')],
            array_map(static fn ($event): string => $event->connection, $trace->events()),
        );
    }

    private function file(string $name): string
    {
        return sys_get_temp_dir().'/query-guard-'.$name.'-'.getmypid().'.sqlite';
    }

    private function connect(): Connection
    {
        return $this->connectTo(['driver' => 'pdo_sqlite', 'memory' => true]);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function connectTo(array $params): Connection
    {
        $configuration = new Configuration();
        $configuration->setMiddlewares([new Middleware()]);

        return DriverManager::getConnection($params, $configuration);
    }
}
