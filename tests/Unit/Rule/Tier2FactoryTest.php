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
        self::assertStringContainsString('tier 2: 1 plans parsed, 0 failed.', end($notices));
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
     * Runs one SELECT per named connection through every tier 2 rule, which is what makes
     * the provider resolve those connections.
     *
     * @param list<string> $connections
     */
    private static function explainQueriesFrom(Tier2Factory $factory, array $connections): void
    {
        $trace = new Trace(new TestIdentifier('id', 'T::t'), TestOptions::none());

        foreach ($connections as $connection) {
            $trace->record(new QueryEvent(
                sql: 'SELECT id, name FROM big WHERE plain_col = ?',
                params: [42],
                connection: $connection,
            ));
        }

        foreach ($factory->rules() as $rule) {
            iterator_to_array($rule->check($trace), false);
        }
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
                public function __construct(private readonly string $platform)
                {
                }

                public function run(string $sql, array $params = []): array
                {
                    $fixture = \dirname(__DIR__, 2).'/Fixture/Explain/'.$this->platform.'/no-index.json';

                    // a size lookup rather than an EXPLAIN — PostgreSQL asks for one
                    if (!str_starts_with(ltrim($sql), 'EXPLAIN')) {
                        return [['rows' => 100000]];
                    }

                    return [['plan' => (string) file_get_contents($fixture)]];
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
        };
    }
}
