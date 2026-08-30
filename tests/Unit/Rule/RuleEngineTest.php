<?php

declare(strict_types=1);

namespace QueryGuard\Test\Unit\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use QueryGuard\Finding\Finding;
use QueryGuard\Query\Trace;
use QueryGuard\Rule\Rule;
use QueryGuard\Rule\RuleEngine;
use QueryGuard\TestIdentifier;
use QueryGuard\TestOptions;

/**
 * The deferred rules are the tier 2 seam: they cannot be built at start-up because no
 * database connection exists yet, and the engine has to keep asking until one does.
 */
#[CoversClass(RuleEngine::class)]
final class RuleEngineTest extends TestCase
{
    public function testIgnoredRulesAreSkipped(): void
    {
        $engine = new RuleEngine([self::rule('n-plus-one'), self::rule('select-star')]);

        $findings = $engine->run($this->trace(['n-plus-one']));

        self::assertCount(1, $findings);
        self::assertSame('select-star', $findings[0]->rule);
    }

    /**
     * An empty answer means "no connection yet", not "no rules" — the factory has to be
     * asked again on the next test, or tier 2 would never start.
     */
    public function testDeferredRulesAreAskedForAgainUntilTheyArrive(): void
    {
        $calls = 0;
        $late = [];

        $engine = new RuleEngine([], static function () use (&$calls, &$late): array {
            ++$calls;

            return $late;
        });

        $engine->run($this->trace());
        self::assertSame(1, $calls);
        self::assertSame([], $engine->ruleIds());

        $late = [self::rule('table-scan')];

        $engine->run($this->trace());
        self::assertSame(2, $calls);
        self::assertSame(['table-scan'], $engine->ruleIds());
    }

    /**
     * Once they have arrived the factory is dropped: rebuilding a plan provider per test
     * would throw away its EXPLAIN cache.
     */
    public function testDeferredRulesAreBuiltOnlyOnce(): void
    {
        $calls = 0;

        $engine = new RuleEngine([], static function () use (&$calls): array {
            ++$calls;

            return [self::rule('table-scan')];
        });

        $engine->run($this->trace());
        $engine->run($this->trace());
        $engine->run($this->trace());

        self::assertSame(1, $calls);
        self::assertSame(['table-scan'], $engine->ruleIds());
    }

    private static function rule(string $id): Rule
    {
        return new class($id) implements Rule {
            public function __construct(private readonly string $id)
            {
            }

            public function id(): string
            {
                return $this->id;
            }

            public function check(Trace $trace): iterable
            {
                yield new Finding(rule: $this->id, test: $trace->test, message: 'found');
            }
        };
    }

    /**
     * @param list<string> $ignored
     */
    private function trace(array $ignored = []): Trace
    {
        return new Trace(
            new TestIdentifier('id', 'SomeTest::testSomething'),
            new TestOptions(ignoredRules: $ignored),
        );
    }
}
