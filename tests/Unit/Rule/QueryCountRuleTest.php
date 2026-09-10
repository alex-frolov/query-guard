<?php

declare(strict_types=1);

namespace QueryGuard\Test\Unit\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use QueryGuard\Query\CallsiteResolver;
use QueryGuard\Query\QueryEvent;
use QueryGuard\Query\Trace;
use QueryGuard\Rule\QueryCountRule;
use QueryGuard\TestIdentifier;
use QueryGuard\TestOptions;

#[CoversClass(QueryCountRule::class)]
final class QueryCountRuleTest extends TestCase
{
    /**
     * Without a threshold in the configuration the rule stays silent: a tool that turns
     * half of a suite red on its first install gets removed the same day.
     */
    public function testWithoutConfiguredThresholdTheRuleIsSilent(): void
    {
        self::assertSame([], $this->findingsFor(new QueryCountRule(null, CallsiteResolver::default()), 100));
    }

    public function testExactlyAtTheThresholdIsNotAFinding(): void
    {
        self::assertSame([], $this->findingsFor(new QueryCountRule(5, CallsiteResolver::default()), 5));
    }

    public function testOverTheThresholdProducesOneFinding(): void
    {
        $findings = $this->findingsFor(new QueryCountRule(5, CallsiteResolver::default()), 6);

        self::assertCount(1, $findings);
        self::assertSame('query-count', $findings[0]->rule);
        self::assertSame('6 queries against a budget of 5', $findings[0]->message);
        self::assertSame(6, $findings[0]->count);
    }

    public function testAllowQueriesAttributeWins(): void
    {
        $rule = new QueryCountRule(2, CallsiteResolver::default());
        $trace = $this->trace(new TestOptions(allowQueries: 10));

        for ($i = 0; $i < 6; ++$i) {
            $trace->record(new QueryEvent('SELECT 1'));
        }

        self::assertSame([], iterator_to_array($rule->check($trace)));
    }

    /**
     * @return list<\QueryGuard\Finding\Finding>
     */
    private function findingsFor(QueryCountRule $rule, int $queries): array
    {
        $trace = $this->trace(TestOptions::none());

        for ($i = 0; $i < $queries; ++$i) {
            $trace->record(new QueryEvent('SELECT 1'));
        }

        return array_values(iterator_to_array($rule->check($trace)));
    }

    private function trace(TestOptions $options): Trace
    {
        return new Trace(new TestIdentifier('id', 'SomeTest::testSomething'), $options);
    }
}
