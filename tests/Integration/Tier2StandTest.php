<?php

declare(strict_types=1);

namespace QueryGuard\Test\Integration;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use QueryGuard\Adapter\AdapterSet;
use QueryGuard\Adapter\Doctrine\DoctrineAdapter;
use QueryGuard\Adapter\Doctrine\Middleware;
use QueryGuard\Collector\DefaultQueryCollector;
use QueryGuard\Finding\Finding;
use QueryGuard\Platform\PlanProvider;
use QueryGuard\Platform\PlatformDriver;
use QueryGuard\Platform\PlatformDrivers;
use QueryGuard\Query\CallsiteResolver;
use QueryGuard\Query\Trace;
use QueryGuard\QueryGuard;
use QueryGuard\Rule\FilesortRule;
use QueryGuard\Rule\NoPossibleIndexRule;
use QueryGuard\Rule\Rule;
use QueryGuard\Rule\TableScanRule;
use QueryGuard\Rule\TemporaryTableRule;
use QueryGuard\TestIdentifier;
use QueryGuard\TestOptions;

/**
 * Tier 2 against a live database from the stand in `tools/stand` — 100 000 rows in `big`.
 *
 * Skipped when the stand is not up: without volume there is nothing to check, and for
 * tier 2 a three-row fixture database is worse than none at all.
 *
 *   docker compose -f tools/stand/docker-compose.yml up -d
 *   tools/stand/run-tier2-tests.sh
 */
#[CoversNothing]
final class Tier2StandTest extends TestCase
{
    private Connection $connection;

    private DefaultQueryCollector $collector;

    private Trace $trace;

    private PlanProvider $plans;

    private PlatformDriver $driver;

    protected function setUp(): void
    {
        $driver = getenv('QG_STAND_DRIVER');

        if (!\is_string($driver) || '' === $driver) {
            self::markTestSkipped('the stand is not up: QG_STAND_DRIVER is not set');
        }

        DoctrineAdapter::reset();

        $configuration = new Configuration();
        $configuration->setMiddlewares([new Middleware()]);

        $this->connection = DriverManager::getConnection([
            'driver' => $driver,
            'host' => (string) getenv('QG_STAND_HOST'),
            'user' => (string) getenv('QG_STAND_USER'),
            'password' => (string) getenv('QG_STAND_PASSWORD'),
            'dbname' => (string) getenv('QG_STAND_DB'),
        ], $configuration);

        $this->collector = new DefaultQueryCollector();
        QueryGuard::activate($this->collector);
        $this->collector->beginFixtures();
        $this->trace = $this->collector->beginTrace(
            new TestIdentifier('id', 'Tier2StandTest::test'),
            TestOptions::none(),
        );

        // the connection is created lazily — without a query the adapter knows nothing yet
        $this->connection->fetchOne('SELECT 1');

        $adapters = new AdapterSet([new DoctrineAdapter()]);
        $explainers = $adapters->explainers();

        self::assertNotSame([], $explainers, 'the adapter must provide an explainer after the first query');

        $explainer = reset($explainers);
        $driver = PlatformDrivers::for($explainer->platform());
        self::assertNotNull($driver, 'the '.$explainer->platform().' platform is not supported');

        $this->driver = $driver;

        // built from the adapter set, exactly as the extension does it: the provider
        // resolves each connection itself when it first sees a query from it
        $this->plans = new PlanProvider($adapters, $this->collector);
    }

    protected function tearDown(): void
    {
        QueryGuard::deactivate();
        DoctrineAdapter::reset();
    }

    public function testFullScanWithoutIndexIsFound(): void
    {
        $this->connection->fetchAllAssociative('SELECT id, name FROM big WHERE plain_col = ?', [42]);

        $findings = $this->findingsFor(new NoPossibleIndexRule($this->plans, CallsiteResolver::default()));

        if (!$this->driver->reportsPossibleIndexes()) {
            self::assertSame([], $findings, 'PostgreSQL does not report candidate indexes — the rule must stay silent');

            return;
        }

        self::assertCount(1, $findings);
        self::assertStringContainsString('"big"', $findings[0]->message);
    }

    public function testIndexedLookupIsClean(): void
    {
        $this->connection->fetchAllAssociative('SELECT id, name FROM big WHERE indexed_col = ?', [42]);

        self::assertSame([], $this->findingsFor(new NoPossibleIndexRule($this->plans, CallsiteResolver::default())));
        self::assertSame([], $this->findingsFor(new TableScanRule($this->plans, CallsiteResolver::default())));
    }

    /**
     * An index exists and would have applied, but the optimiser chose a full scan —
     * that is `table-scan`, not "no index".
     */
    public function testTableScanWithAvailableIndexIsFound(): void
    {
        $this->connection->fetchAllAssociative('SELECT id, name FROM big WHERE indexed_col > ?', [-1]);

        $findings = $this->findingsFor(new TableScanRule($this->plans, CallsiteResolver::default()));

        self::assertNotSame([], $findings);
        self::assertStringContainsString('full scan', $findings[0]->message);
        self::assertStringContainsString('"big"', $findings[0]->message);
    }

    /**
     * The two platforms describe the same join differently, and that is not a bug but a
     * difference in the questions they answer.
     *
     * `child.big_id` has no index. MySQL reports that no index could have applied at all,
     * which makes it `no-possible-index`. PostgreSQL does not answer that question, so
     * `table-scan` is what remains. Both findings point at the same missing line in
     * the schema.
     */
    public function testTheSameJoinIsDescribedDifferentlyByPlatforms(): void
    {
        $this->connection->fetchAllAssociative(
            'SELECT b.id, c.note FROM big b JOIN child c ON c.big_id = b.id WHERE b.indexed_col = ?',
            [42],
        );

        $missingIndex = $this->findingsFor(new NoPossibleIndexRule($this->plans, CallsiteResolver::default()));
        $tableScan = $this->findingsFor(new TableScanRule($this->plans, CallsiteResolver::default()));

        if ($this->driver->reportsPossibleIndexes()) {
            self::assertNotSame([], $missingIndex, 'MySQL: child has no candidate indexes');
            self::assertSame([], $tableScan, 'and it is not table-scan — a different diagnosis');
        } else {
            self::assertSame([], $missingIndex, 'PostgreSQL says nothing about candidates');
            self::assertNotSame([], $tableScan, 'but the full scan is visible');
        }
    }

    public function testSortWithoutIndexIsFound(): void
    {
        $this->connection->fetchAllAssociative('SELECT id, name FROM big ORDER BY plain_col');

        self::assertNotSame([], $this->findingsFor(new FilesortRule($this->plans, CallsiteResolver::default())));
    }

    public function testTemporaryTableRuleFollowsThePlatform(): void
    {
        $this->connection->fetchAllAssociative('SELECT plain_col, COUNT(*) AS n FROM big GROUP BY plain_col');

        $findings = $this->findingsFor(new TemporaryTableRule($this->plans, CallsiteResolver::default()));

        if ($this->driver->reportsTemporaryTable()) {
            self::assertNotSame([], $findings);
        } else {
            self::assertSame([], $findings, "PostgreSQL's HashAggregate is normal, not a finding");
        }
    }

    /**
     * The main lesson from the recon: on a small table the plan rules must stay silent.
     */
    public function testSmallTableIsNeverReported(): void
    {
        $this->connection->executeStatement('DROP TABLE IF EXISTS tiny');
        $this->connection->executeStatement('CREATE TABLE tiny (id INT PRIMARY KEY, note VARCHAR(20))');
        $this->connection->executeStatement("INSERT INTO tiny (id, note) VALUES (1, 'a'), (2, 'b'), (3, 'c')");
        // ANALYZE TABLE returns a result set in MySQL: not reading it leaves an open
        // cursor and the next query fails with "unbuffered queries are active"
        $this->connection->fetchAllAssociative($this->analyzeSql('tiny'));

        $this->collector->beginTrace(new TestIdentifier('id', 'reset'), TestOptions::none());
        $this->connection->fetchAllAssociative('SELECT id, note FROM tiny WHERE note = ?', ['a']);
        $trace = $this->collector->endTrace();
        self::assertNotNull($trace);

        $rule = new TableScanRule($this->plans, CallsiteResolver::default());

        self::assertSame([], array_values(iterator_to_array($rule->check($trace))));

        $this->connection->executeStatement('DROP TABLE tiny');
    }

    /**
     * EXPLAIN is a query too, and it must not land in the trace — otherwise the tool
     * would start counting its own traffic.
     */
    public function testExplainQueriesStayOutOfTheTrace(): void
    {
        $this->connection->fetchAllAssociative('SELECT id FROM big WHERE indexed_col = ?', [1]);
        $before = $this->trace->count();

        $this->findingsFor(new TableScanRule($this->plans, CallsiteResolver::default()));

        self::assertSame($before, $this->trace->count());
    }

    private function analyzeSql(string $table): string
    {
        return 'mysql' === $this->driver->name()
            ? 'ANALYZE TABLE '.$table
            : 'ANALYZE '.$table;
    }

    /**
     * @return list<Finding>
     */
    private function findingsFor(Rule $rule): array
    {
        return array_values(iterator_to_array($rule->check($this->trace)));
    }
}
