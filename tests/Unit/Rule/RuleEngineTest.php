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
 * The engine is deliberately thin: it owns the `#[IgnoreRule]` decision and nothing else.
 *
 * It used to also carry a deferred set of rules for tier 2, which could not be built
 * before a database connection existed. `PlanProvider` now resolves a connection when it
 * first sees a query from it, so every rule is built up front and that machinery is gone.
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
