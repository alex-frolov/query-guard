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
use QueryGuard\Rule\Tier2Factory;

/**
 * The notices are half of what tier 2 is worth: a rule that cannot judge on this
 * platform has to say so, because a green report and "we did not look" must not be the
 * same output.
 */
#[CoversClass(Tier2Factory::class)]
final class Tier2FactoryTest extends TestCase
{
    public function testWithoutAConnectionNothingIsBuiltAndTheSummarySaysSo(): void
    {
        $factory = self::factory(null);

        self::assertSame([], $factory->rules());
        self::assertSame(
            ['tier 2 is enabled but no database connection appeared during the run — no plans were looked at.'],
            $factory->notices(),
        );
    }

    public function testAnUnsupportedPlatformIsNamedRatherThanIgnored(): void
    {
        $factory = self::factory('sqlite');

        self::assertSame([], $factory->rules());

        $notices = $factory->notices();

        self::assertCount(1, $notices);
        self::assertStringContainsString('"sqlite" platform is not supported', $notices[0]);
        self::assertStringContainsString('MySQL/MariaDB and PostgreSQL', $notices[0]);
    }

    public function testMySqlBuildsEveryRuleAndWarnsAboutNothing(): void
    {
        $factory = self::factory('mysql');

        self::assertSame(
            ['no-possible-index', 'table-scan', 'filesort', 'temporary-table'],
            array_map(static fn ($rule): string => $rule->id(), $factory->rules()),
        );

        // only the counter line, no "does not work here" warnings
        self::assertSame(['tier 2: 0 plans parsed, 0 failed.'], $factory->notices());
    }

    /**
     * PostgreSQL answers neither "which indexes could have applied" nor "is this a
     * temporary table". Both rules are still built — and both say out loud that they
     * cannot judge.
     */
    public function testPostgresNamesTheRulesThatCannotJudgeThere(): void
    {
        $factory = self::factory('pgsql');

        self::assertCount(4, $factory->rules());

        $notices = $factory->notices();

        self::assertCount(3, $notices);
        self::assertStringContainsString('no-possible-index rule does not work', $notices[0]);
        self::assertStringContainsString('not "all clear"', $notices[0]);
        self::assertStringContainsString('temporary-table rule does not work', $notices[1]);
        self::assertStringContainsString('plans parsed', $notices[2]);
    }

    /**
     * Once the rules exist the factory is spent: `RuleEngine` merges them in and stops
     * asking.
     */
    public function testRulesAreHandedOverOnlyOnce(): void
    {
        $factory = self::factory('mysql');

        self::assertCount(4, $factory->rules());
        self::assertSame([], $factory->rules());
    }

    private static function factory(?string $platform): Tier2Factory
    {
        return new Tier2Factory(
            new AdapterSet([self::adapter($platform)]),
            new DefaultQueryCollector(),
            new CallsiteResolver([]),
        );
    }

    private static function adapter(?string $platform): OrmAdapter
    {
        $explainer = null === $platform ? null : new class($platform) implements Explainer {
            public function __construct(private readonly string $platform)
            {
            }

            public function run(string $sql, array $params = []): array
            {
                return [];
            }

            public function platform(): string
            {
                return $this->platform;
            }
        };

        return new class($explainer) implements OrmAdapter {
            public function __construct(private readonly ?Explainer $explainer)
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
                return null !== $this->explainer;
            }

            public function explainer(): ?Explainer
            {
                return $this->explainer;
            }

            public function installationHint(): string
            {
                return 'nothing to do';
            }
        };
    }
}
