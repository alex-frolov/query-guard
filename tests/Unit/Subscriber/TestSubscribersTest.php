<?php

declare(strict_types=1);

namespace QueryGuard\Test\Unit\Subscriber;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use QueryGuard\Adapter\AdapterSet;
use QueryGuard\Baseline\Baseline;
use QueryGuard\Collector\DefaultQueryCollector;
use QueryGuard\Collector\Phase;
use QueryGuard\Finding\Finding;
use QueryGuard\Query\QueryEvent;
use QueryGuard\Query\Trace;
use QueryGuard\Report\Report;
use QueryGuard\Rule\Rule;
use QueryGuard\Rule\RuleEngine;
use QueryGuard\Subscriber\Test\ErroredSubscriber;
use QueryGuard\Subscriber\Test\FailedSubscriber;
use QueryGuard\Subscriber\Test\FinishedSubscriber;
use QueryGuard\Subscriber\Test\PreparationStartedSubscriber;
use QueryGuard\Subscriber\Test\PreparedSubscriber;
use QueryGuard\Test\Unit\Fixture\AnnotatedSubject;
use QueryGuard\Test\Unit\Fixture\Events;
use QueryGuard\Test\Unit\Fixture\FakeOrmAdapter;
use QueryGuard\TestIdentifier;

/**
 * The four subscribers that run per test, driven by hand-built PHPUnit events.
 *
 * The end-to-end suite launches a real runner and asserts on its output, which is the
 * only way to check that PHPUnit calls these at all — but it runs in a child process,
 * so what each subscriber decides on its own is asserted here instead.
 */
#[CoversClass(PreparationStartedSubscriber::class)]
#[CoversClass(PreparedSubscriber::class)]
#[CoversClass(FinishedSubscriber::class)]
#[CoversClass(FailedSubscriber::class)]
#[CoversClass(ErroredSubscriber::class)]
final class TestSubscribersTest extends TestCase
{
    private DefaultQueryCollector $collector;

    private FakeOrmAdapter $adapter;

    private AdapterSet $adapters;

    private Report $report;

    protected function setUp(): void
    {
        $this->collector = new DefaultQueryCollector();
        $this->adapter = new FakeOrmAdapter();
        $this->adapters = new AdapterSet([$this->adapter]);
        $this->report = new Report();
    }

    public function testPreparationStartedOpensTheFixtureBucket(): void
    {
        (new PreparationStartedSubscriber($this->collector, $this->adapters))->notify(Events::preparationStarted());

        self::assertSame(Phase::Fixtures, $this->collector->phase());
    }

    /**
     * Laravel throws the application away between tests, and the listener goes with it.
     * Reinstalling before every test is what keeps the adapter alive.
     */
    public function testPreparationStartedReinstallsTheAdapters(): void
    {
        $subscriber = new PreparationStartedSubscriber($this->collector, $this->adapters);

        $subscriber->notify(Events::preparationStarted());
        $subscriber->notify(Events::preparationStarted());

        self::assertSame(2, $this->adapter->installations);
    }

    public function testPreparedOpensTheTraceWithTheOptionsOfTheTest(): void
    {
        $this->collector->beginFixtures();
        $this->collector->record($this->event('SELECT 1'));

        (new PreparedSubscriber($this->collector, $this->adapters))->notify(Events::prepared());

        $this->collector->record($this->event('SELECT 2'));
        $trace = $this->collector->endTrace();

        self::assertInstanceOf(Trace::class, $trace);
        self::assertSame(AnnotatedSubject::class, $trace->test->className);
        self::assertSame('inheritsClassOptions', $trace->test->methodName);
        self::assertStringContainsString('AnnotatedSubject::inheritsClassOptions', $trace->test->label);

        // read off the attributes of the class the event names
        self::assertSame(10, $trace->options->allowQueries);
        self::assertTrue($trace->options->isIgnored('duplicate-query'));

        // the query from before `Prepared` is counted, not traced — that boundary is the
        // whole reason the trace opens here rather than at `PreparationStarted`
        self::assertSame(1, $trace->fixtureQueryCount());
        self::assertSame(1, $trace->count());
    }

    /**
     * The last chance to subscribe: Laravel creates the application inside `setUp()`,
     * after `PreparationStarted` has already been and gone.
     */
    public function testPreparedInstallsTheAdaptersAgain(): void
    {
        (new PreparedSubscriber($this->collector, $this->adapters))->notify(Events::prepared());

        self::assertSame(1, $this->adapter->installations);
    }

    public function testPreparedHandlesATestThatIsNotAMethod(): void
    {
        (new PreparedSubscriber($this->collector, $this->adapters))->notify(Events::prepared(Events::phpt()));

        $trace = $this->collector->endTrace();

        self::assertInstanceOf(Trace::class, $trace);
        self::assertNull($trace->test->className);
        self::assertNull($trace->test->methodName);
        self::assertStringContainsString('example.phpt', $trace->test->label);
        self::assertNull($trace->options->allowQueries);
    }

    /**
     * A test PHPUnit skipped or filtered out never reached `Prepared`, so there is no
     * trace to close and nothing to report.
     */
    public function testFinishedWithoutAnOpenTraceReportsNothing(): void
    {
        $this->finishedSubscriber([$this->rule()])->notify(Events::finished());

        self::assertSame(0, $this->report->tests());
        self::assertSame([], $this->report->findings());
    }

    public function testFinishedRunsTheRulesAndPutsTheFindingsInTheReport(): void
    {
        $this->openTrace();

        $this->finishedSubscriber([$this->rule()])->notify(Events::finished());

        self::assertSame(1, $this->report->tests());
        self::assertCount(1, $this->report->findings());
        self::assertSame(0, $this->report->suppressed());
    }

    public function testFinishedCountsABaselinedFindingAsSuppressed(): void
    {
        $this->openTrace();

        $baseline = Baseline::empty();
        $baseline->add($this->finding());

        $this->finishedSubscriber([$this->rule()], $baseline)->notify(Events::finished());

        // the test itself still counts: a silenced finding is not a missing test
        self::assertSame(1, $this->report->tests());
        self::assertSame([], $this->report->findings());
        self::assertSame(1, $this->report->suppressed());
    }

    /**
     * While regenerating, everything found goes into the file rather than the summary —
     * otherwise the run that writes the baseline is also the run that fails on it.
     */
    public function testGeneratingABaselineKeepsTheFindingsOutOfTheSummary(): void
    {
        $this->openTrace();
        $generated = Baseline::empty();

        $this->finishedSubscriber([$this->rule()], Baseline::empty(), $generated)->notify(Events::finished());

        self::assertSame(1, $generated->count());
        self::assertSame([], $this->report->findings());
        self::assertSame(1, $this->report->suppressed());
    }

    public function testFailedMarksTheRunnerAsFailing(): void
    {
        (new FailedSubscriber($this->report))->notify(Events::failed());

        self::assertTrue($this->report->isRunnerFailing());
    }

    public function testErroredMarksTheRunnerAsFailing(): void
    {
        (new ErroredSubscriber($this->report))->notify(Events::errored());

        self::assertTrue($this->report->isRunnerFailing());
    }

    /**
     * @param list<Rule> $rules
     */
    private function finishedSubscriber(array $rules, ?Baseline $baseline = null, ?Baseline $generated = null): FinishedSubscriber
    {
        return new FinishedSubscriber(
            $this->collector,
            new RuleEngine($rules),
            $this->report,
            $baseline ?? Baseline::empty(),
            $generated,
        );
    }

    private function openTrace(): void
    {
        (new PreparedSubscriber($this->collector, $this->adapters))->notify(Events::prepared());
        $this->collector->record($this->event('SELECT 1'));
    }

    /**
     * A rule that always finds the one finding below, so that the subscriber's own
     * decisions — baseline, regeneration, counting — are what the assertions are about.
     */
    private function rule(): Rule
    {
        $finding = $this->finding();

        return new class($finding) implements Rule {
            public function __construct(private readonly Finding $finding)
            {
            }

            public function id(): string
            {
                return 'demo';
            }

            public function check(Trace $trace): iterable
            {
                yield $this->finding;
            }
        };
    }

    private function finding(): Finding
    {
        return new Finding(
            rule: 'demo',
            test: new TestIdentifier('id', 'SomeTest::testSomething'),
            message: 'found something',
            signature: 'demo|/project/src/A.php|select 1',
        );
    }

    private function event(string $sql): QueryEvent
    {
        return new QueryEvent(
            sql: $sql,
            stack: [['file' => '/project/src/A.php', 'line' => 5, 'function' => 'query']],
        );
    }
}
