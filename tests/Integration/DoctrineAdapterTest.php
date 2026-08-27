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
        self::assertCount(5, reset($groups));
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

    private function connect(): Connection
    {
        $configuration = new Configuration();
        $configuration->setMiddlewares([new Middleware()]);

        return DriverManager::getConnection(
            ['driver' => 'pdo_sqlite', 'memory' => true],
            $configuration,
        );
    }
}
