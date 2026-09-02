<?php

declare(strict_types=1);

namespace QueryGuard\Test\Unit\Subscriber;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use QueryGuard\Adapter\AdapterSet;
use QueryGuard\Baseline\Baseline;
use QueryGuard\Collector\DefaultQueryCollector;
use QueryGuard\Finding\Finding;
use QueryGuard\Finding\Severity;
use QueryGuard\Mode;
use QueryGuard\Query\CallsiteResolver;
use QueryGuard\Query\QueryEvent;
use QueryGuard\Query\Trace;
use QueryGuard\Report\Report;
use QueryGuard\Rule\Tier2Factory;
use QueryGuard\Subscriber\TestRunner\ExecutionFinishedSubscriber;
use QueryGuard\Test\Unit\Fixture\Events;
use QueryGuard\Test\Unit\Fixture\FakeOrmAdapter;
use QueryGuard\Test\Unit\Fixture\RecordingReporter;
use QueryGuard\TestIdentifier;
use QueryGuard\TestOptions;

/**
 * The end-of-run subscriber: the notices it adds before the summary is printed.
 *
 * Most of them exist because silence is the failure mode that matters here — a green
 * summary over a trace nothing ever reached is how a tool of this kind loses trust.
 *
 * One branch is deliberately missing: `strict` mode failing the run registers a shutdown
 * function that calls `exit(1)`, and a unit test cannot exercise that without taking the
 * suite down with it. The end-to-end suite runs a real PHPUnit and asserts on its exit
 * code instead; what is checked here is every path that must *not* reach it.
 */
#[CoversClass(ExecutionFinishedSubscriber::class)]
final class ExecutionFinishedSubscriberTest extends TestCase
{
    private DefaultQueryCollector $collector;

    private Report $report;

    private RecordingReporter $reporter;

    protected function setUp(): void
    {
        $this->collector = new DefaultQueryCollector();
        $this->report = new Report();
        $this->reporter = new RecordingReporter();
    }

    public function testTheSummaryIsPrintedWithTheModeItRanIn(): void
    {
        $this->notify($this->adapters());

        self::assertSame($this->report, $this->reporter->report);
        self::assertSame(Mode::Report, $this->reporter->mode);
    }

    public function testNoOrmAtAllIsSaidOutLoud(): void
    {
        $this->notify(new AdapterSet([]));

        self::assertStringContainsString('neither Doctrine nor Eloquent was found', $this->notices());
    }

    /**
     * An ORM that is present but did not hook in is a different failure from having no
     * ORM, and needs the hint that says how to fix it.
     */
    public function testAnOrmThatFailedToHookInIsNamedWithItsHint(): void
    {
        $this->notify($this->adapters(installed: false));

        $notices = $this->notices();

        self::assertStringContainsString('an ORM was found (fake) but interception did not take', $notices);
        self::assertStringContainsString('fake: put the middleware in the test configuration.', $notices);
    }

    public function testInterceptionThatSawNothingIsNotAllClear(): void
    {
        $this->notify($this->adapters());

        self::assertStringContainsString('not a single query ran during the whole suite', $this->notices());
    }

    public function testNothingIsSaidWhenQueriesWereCollected(): void
    {
        $this->collector->beginFixtures();
        $this->collector->record($this->event());

        $this->notify($this->adapters());

        self::assertSame([], $this->report->notices());
    }

    /**
     * Queries from a bootstrap file or a data provider fall outside every test, so no
     * rule ever sees them. The count has to be visible, or they look examined.
     */
    public function testQueriesOutsideAnyTestAreCounted(): void
    {
        $this->collector->record($this->event());
        $this->collector->record($this->event());

        $this->notify($this->adapters());

        self::assertStringContainsString('2 queries ran outside the boundaries of a test', $this->notices());
    }

    public function testTier2AddsItsOwnNotices(): void
    {
        $tier2 = new Tier2Factory(new AdapterSet([]), $this->collector, new CallsiteResolver([]));

        $this->notify($this->adapters(), tier2: $tier2);

        self::assertStringContainsString('tier 2 is enabled but no database connection appeared', $this->notices());
    }

    public function testARegeneratedBaselineIsWrittenAndReported(): void
    {
        $path = sys_get_temp_dir().'/query-guard-generated-'.getmypid().'.json';
        $generated = Baseline::empty();
        $generated->add($this->finding());

        try {
            $this->notify($this->adapters(), generated: $generated, baselinePath: $path);

            self::assertStringContainsString('baseline regenerated: '.$path.', 1 findings inside.', $this->notices());
            self::assertFileExists($path);
        } finally {
            @unlink($path);
        }
    }

    /**
     * A baseline that could not be written must not pass for one that was: the next run
     * would silently fail on findings the developer believes are recorded.
     */
    public function testABaselineThatCouldNotBeWrittenSaysSo(): void
    {
        $path = sys_get_temp_dir().'/query-guard-no-such-directory-'.getmypid().'/baseline.json';

        $this->notify($this->adapters(), generated: Baseline::empty(), baselinePath: $path);

        self::assertStringContainsString('could not write the baseline to '.$path, $this->notices());
    }

    /**
     * `strict` can only fail a run from a shutdown function, which replaces PHPUnit's own
     * exit code. When PHPUnit is failing the run anyway, its code is the more specific
     * one (2 for errors, 1 for failures) and is left alone.
     */
    public function testStrictLeavesTheExitCodeAloneWhenPhpunitIsAlreadyFailing(): void
    {
        $this->report->addTrace($this->trace(), [$this->finding()]);
        $this->report->markRunnerFailing();

        $this->notify($this->adapters(), mode: Mode::Strict);

        self::assertSame(Mode::Strict, $this->reporter->mode);
        self::assertTrue($this->report->hasFindingsAtLeast(Severity::Warning));
    }

    /**
     * Everything found is printed; only what reaches `fail-on` fails the run. `info` is
     * below the default, so a run holding nothing else stays green.
     */
    public function testFindingsBelowTheFailOnSeverityDoNotFailTheRun(): void
    {
        $this->report->addTrace($this->trace(), [$this->finding(Severity::Info)]);

        $this->notify($this->adapters(), mode: Mode::Strict);

        self::assertFalse($this->report->hasFindingsAtLeast(Severity::Warning));
        self::assertSame(Mode::Strict, $this->reporter->mode);
    }

    private function notify(
        AdapterSet $adapters,
        Mode $mode = Mode::Report,
        ?Baseline $generated = null,
        string $baselinePath = '',
        ?Tier2Factory $tier2 = null,
    ): void {
        (new ExecutionFinishedSubscriber(
            $this->report,
            $this->reporter,
            $this->collector,
            $adapters,
            $mode,
            $generated,
            $baselinePath,
            $tier2,
        ))->notify(Events::executionFinished());
    }

    private function adapters(bool $installed = true): AdapterSet
    {
        return new AdapterSet([new FakeOrmAdapter('fake', $installed)]);
    }

    private function notices(): string
    {
        return implode("\n", $this->report->notices());
    }

    private function finding(Severity $severity = Severity::Warning): Finding
    {
        return new Finding(
            rule: 'demo',
            test: new TestIdentifier('id', 'SomeTest::testSomething'),
            message: 'found something',
            severity: $severity,
            signature: 'demo|/project/src/A.php|select 1',
        );
    }

    private function trace(): Trace
    {
        return new Trace(new TestIdentifier('id', 'SomeTest::testSomething'), TestOptions::none());
    }

    private function event(): QueryEvent
    {
        return new QueryEvent(
            sql: 'SELECT 1',
            stack: [['file' => '/project/src/A.php', 'line' => 5, 'function' => 'query']],
        );
    }
}
