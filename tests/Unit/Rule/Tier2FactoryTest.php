<?php

declare(strict_types=1);

namespace QueryGuard\Test\Unit\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use QueryGuard\Adapter\AdapterSet;
use QueryGuard\Adapter\Explainer;
use QueryGuard\Adapter\OrmAdapter;
use QueryGuard\Collector\DefaultQueryCollector;
use QueryGuard\Collector\QueryCollector;
use QueryGuard\Query\CallsiteResolver;
use QueryGuard\Query\QueryEvent;
use QueryGuard\Query\Trace;
use QueryGuard\Rule\Tier2Factory;
use QueryGuard\TestIdentifier;
use QueryGuard\TestOptions;

/**
 * The notices are half of what tier 2 is worth: a rule that cannot judge on this
 * platform has to say so, because a green report and "we did not look" must not be the
 * same output.
 *
 * Since the explainer is resolved per connection, what tier 2 can say is only known
 * after queries have gone past it — so these tests drive a trace through the rules
 * rather than inspecting the factory on its own.
 */
#[CoversClass(Tier2Factory::class)]
final class Tier2FactoryTest extends TestCase
{
    /**
     * The rules no longer wait for a connection: `PlanProvider` resolves one when it
     * first sees a query from it, so there is nothing to defer and nothing to hand over
     * "only once".
     */
    public function testEveryRuleIsBuiltUpFrontEvenWithNoConnectionAtAll(): void
    {
        $factory = self::factory([]);

        self::assertSame(
            ['no-possible-index', 'table-scan', 'filesort', 'temporary-table'],
            array_map(static fn ($rule): string => $rule->id(), $factory->rules()),
        );
    }

    public function testWithoutAConnectionTheSummarySaysSo(): void
    {
        $factory = self::factory([]);
        self::explainQueriesFrom($factory, ['default']);

        self::assertSame(
            ['tier 2 is enabled but no database connection appeared during the run — no plans were looked at.'],
            $factory->notices(),
        );
    }

    /**
     * The regression this refactor is about.
     *
     * A secondary SQLite connection used to switch tier 2 off for the whole run: a single
     * explainer was resolved once, and whichever connection was open first decided
     * everything. The MySQL database has to keep being explained, and the SQLite one has
     * to be named rather than blamed on "the platform".
     */
    public function testAnUnsupportedConnectionDoesNotDisableTheSupportedOnes(): void
    {
        $factory = self::factory(['cache' => 'sqlite', 'primary' => 'mysql']);
        self::explainQueriesFrom($factory, ['cache', 'primary']);

        $notices = $factory->notices();

        self::assertStringContainsString('the "cache" connection runs on "sqlite"', $notices[0]);
        self::assertStringContainsString('Other connections are unaffected', $notices[0]);

        // the MySQL connection was still explained — the counter proves tier 2 stayed on
        self::assertStringContainsString('tier 2: 1 plans parsed, 0 failed.', implode("\n", $notices));
    }

    /**
     * A caveat belongs to a platform that actually answered. It used to be emitted for
     * whichever connection happened to be resolved first, so a project on both databases
     * got PostgreSQL's caveats on a MySQL run or the other way round.
     */
    public function testCaveatsComeFromThePlatformsThatActuallyAnswered(): void
    {
        $onlyMySql = self::factory(['primary' => 'mysql']);
        self::explainQueriesFrom($onlyMySql, ['primary']);

        // MySQL answers both questions, so there is nothing to caveat
        self::assertSame(['tier 2: 1 plans parsed, 0 failed.'], $onlyMySql->notices());

        $bothDatabases = self::factory(['primary' => 'mysql', 'analytics' => 'pgsql']);
        self::explainQueriesFrom($bothDatabases, ['primary', 'analytics']);

        $notices = implode("\n", $bothDatabases->notices());

        self::assertStringContainsString('no-possible-index rule does not work on "pgsql"', $notices);
        self::assertStringContainsString('temporary-table rule does not work on "pgsql"', $notices);
        self::assertStringContainsString('not "all clear"', $notices);
    }

    public function testPostgresNamesTheRulesThatCannotJudgeThere(): void
    {
        $factory = self::factory(['primary' => 'pgsql']);
        self::explainQueriesFrom($factory, ['primary']);

        $notices = $factory->notices();

        self::assertCount(3, $notices);
        self::assertStringContainsString('no-possible-index rule does not work', $notices[0]);
        self::assertStringContainsString('temporary-table rule does not work', $notices[1]);
        self::assertStringContainsString('plans parsed', $notices[2]);
    }

    public function testEveryConnectionUnsupportedIsSaidDifferently(): void
    {
        $factory = self::factory(['cache' => 'sqlite']);
        self::explainQueriesFrom($factory, ['cache']);

        $notices = $factory->notices();

        self::assertStringContainsString('the "cache" connection runs on "sqlite"', $notices[0]);
        self::assertStringContainsString('every connection it saw was on an unsupported platform', $notices[1]);
    }

    /**
     * A unique-key lookup that found no row gives a plan with no table in it. It used to
     * be counted as a failure — a quarter of all plans on a MySQL project — which made
     * tier 2 look broken when there was simply nothing to judge.
     */
    public function testAPlanThatReadsNoTableIsNotAFailure(): void
    {
        $factory = self::factory(['primary' => 'mysql/no-matching-row', 'secondary' => 'mysql/no-tables-used']);
        self::explainQueriesFrom($factory, ['primary', 'secondary']);

        self::assertSame(
            ["tier 2: 0 plans parsed, 2 had nothing to judge, 0 failed.\n"
            .'Nothing to judge: the optimiser answered without reading a table — '
            .'"no matching row in const table" (1), "No tables used" (1).'],
            $factory->notices(),
        );
    }

    /**
     * A failure carries its cause to the summary. Before, `catch (\Throwable)` dropped it,
     * and the only way to learn why a plan failed was to patch `vendor/`.
     */
    public function testTheFirstFailureOfEachKindReachesTheSummary(): void
    {
        $factory = self::factory(['primary' => 'mysql/throw', 'secondary' => 'mysql/garbage']);
        self::explainQueriesFrom($factory, ['primary', 'secondary']);

        $notices = $factory->notices();

        self::assertSame('tier 2: 0 plans parsed, 2 failed.', $notices[0]);
        self::assertStringContainsString('RuntimeException: SQLSTATE[42000]: syntax error', $notices[1]);
        self::assertStringContainsString('unreadable plan: the plan was not JSON the driver could read: not a plan', $notices[1]);
        self::assertStringNotContainsString('second line', $notices[1], 'only the first line of a message');
    }

    /**
     * A derived table is read with `ALL` and has no `possible_keys` — by definition.
     * On a live project that was a third of all `error` findings of tier 2. The verdict
     * belongs to the tables inside the subquery, which here use their indexes.
     */
    public function testADerivedTableGetsNoIndexOrScanFinding(): void
    {
        $factory = self::factory(['primary' => 'mysql/derived-scan']);

        self::assertSame([], self::explainQueriesFrom($factory, ['primary']));
        self::assertSame(['tier 2: 1 plans parsed, 0 failed.'], $factory->notices());
    }

    /**
     * `findAll()` on a lookup table reads every row, and no index could change that —
     * yet it was "a scan with no alternative", 843 of 927 findings on one MySQL suite.
     * With a condition and no index the finding stays.
     */
    public function testNoPossibleIndexJudgesOnlyFilteredScans(): void
    {
        $unfiltered = self::factory(['primary' => 'mysql/no-condition']);
        self::assertSame([], self::explainQueriesFrom($unfiltered, ['primary']));

        $filtered = self::factory(['primary' => 'mysql/no-index']);
        $findings = self::explainQueriesFrom($filtered, ['primary']);

        self::assertSame(['no-possible-index'], array_map(static fn ($f): string => $f->rule, $findings));
        self::assertSame('no suitable index on "big" — a scan with no alternative', $findings[0]->message);
    }

    /**
     * The plan names the table `big`; in this query that is an alias. The message names
     * the table, the signature keeps the alias — existing baselines hold it.
     */
    public function testAMessageNamesTheTableBehindAnAlias(): void
    {
        $factory = self::factory(['primary' => 'mysql/no-index']);
        $findings = self::explainQueriesFrom($factory, ['primary'], 'SELECT big.id FROM huge_table big WHERE big.plain_col = ?');

        self::assertSame('no suitable index on "huge_table" (big) — a scan with no alternative', $findings[0]->message);
        self::assertStringEndsWith('#big', $findings[0]->signature);
    }

    /**
     * Runs one SELECT per named connection through every tier 2 rule, which is what makes
     * the provider resolve those connections.
     *
     * @param list<string> $connections
     *
     * @return list<\QueryGuard\Finding\Finding>
     */
    private static function explainQueriesFrom(
        Tier2Factory $factory,
        array $connections,
        string $sql = 'SELECT id, name FROM big WHERE plain_col = ?',
    ): array {
        $trace = new Trace(new TestIdentifier('id', 'T::t'), TestOptions::none());

        foreach ($connections as $connection) {
            $trace->record(new QueryEvent(
                sql: $sql,
                params: [42],
                connection: $connection,
            ));
        }

        $findings = [];

        foreach ($factory->rules() as $rule) {
            array_push($findings, ...iterator_to_array($rule->check($trace), false));
        }

        return $findings;
    }

    /**
     * @param array<string, string> $platformByConnection
     */
    private static function factory(array $platformByConnection): Tier2Factory
    {
        return new Tier2Factory(
            new AdapterSet([self::adapter($platformByConnection)]),
            new DefaultQueryCollector(),
            new CallsiteResolver([]),
        );
    }

    /**
     * @param array<string, string> $platformByConnection
     */
    private static function adapter(array $platformByConnection): OrmAdapter
    {
        $explainers = [];

        foreach ($platformByConnection as $connection => $platform) {
            $explainers[$connection] = new class($platform) implements Explainer {
                private readonly string $platform;

                private readonly string $fixture;

                /**
                 * @param string $spec `platform` or `platform/fixture`; the fixtures
                 *                     `throw` and `garbage` stand for a failing EXPLAIN
                 */
                public function __construct(string $spec)
                {
                    [$this->platform, $this->fixture] = explode('/', $spec.'/no-index');
                }

                public function run(string $sql, array $params = []): array
                {
                    // a size lookup rather than an EXPLAIN — PostgreSQL asks for one
                    if (!str_starts_with(ltrim($sql), 'EXPLAIN')) {
                        return [['rows' => 100000]];
                    }

                    if ('throw' === $this->fixture) {
                        throw new \RuntimeException("SQLSTATE[42000]: syntax error\nsecond line");
                    }

                    if ('garbage' === $this->fixture) {
                        return [['plan' => 'not a plan']];
                    }

                    $path = \dirname(__DIR__, 2).'/Fixture/Explain/'.$this->platform.'/'.$this->fixture.'.json';

                    return [['plan' => (string) file_get_contents($path)]];
                }

                public function platform(): string
                {
                    return $this->platform;
                }
            };
        }

        return new class($explainers) implements OrmAdapter {
            /**
             * @param array<string, Explainer> $explainers
             */
            public function __construct(private readonly array $explainers)
            {
            }

            public static function supports(): bool
            {
                return true;
            }

            public function name(): string
            {
                return 'fake';
            }

            public function install(QueryCollector $collector): void
            {
            }

            public function isInstalled(): bool
            {
                return [] !== $this->explainers;
            }

            public function explainers(): array
            {
                return $this->explainers;
            }

            public function installationHint(): string
            {
                return 'nothing to do';
            }

            public function notices(): array
            {
                return [];
            }
        };
    }
}
