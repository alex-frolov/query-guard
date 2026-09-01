<?php

declare(strict_types=1);

namespace QueryGuard\Test\Unit\Report;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use QueryGuard\Finding\Finding;
use QueryGuard\Finding\Severity;
use QueryGuard\Mode;
use QueryGuard\Query\Callsite;
use QueryGuard\Query\QueryEvent;
use QueryGuard\Query\Trace;
use QueryGuard\Report\ConsoleReporter;
use QueryGuard\Report\Report;
use QueryGuard\TestIdentifier;
use QueryGuard\TestOptions;

#[CoversClass(ConsoleReporter::class)]
final class ConsoleReporterTest extends TestCase
{
    public function testCleanRunSaysSo(): void
    {
        $report = new Report();
        $report->addTrace($this->trace(3, 2), []);

        self::assertStringContainsString('tests traced: 1, queries: 3 (in setUp: 2)', $this->render($report));
        self::assertStringContainsString('no findings', $this->render($report));
    }

    public function testFindingIsPrintedWithRuleTestMessageAndCallsite(): void
    {
        $report = new Report();
        $report->addTrace($this->trace(6, 0), [
            new Finding(
                rule: 'query-count',
                test: new TestIdentifier('id', 'OrderTest::testList'),
                message: '6 queries against a budget of 5',
                callsite: new Callsite('/app/src/Repository/OrderRepository.php', 42),
            ),
        ]);

        $output = $this->render($report, basePath: '/app');

        self::assertStringContainsString('findings: 1', $output);
        self::assertStringContainsString('* [warning] query-count — OrderTest::testList', $output);
        self::assertStringContainsString('6 queries against a budget of 5', $output);
        self::assertStringContainsString('src/Repository/OrderRepository.php:42', $output);
    }

    /**
     * What is certain — lazy loading with the association named — comes first, otherwise
     * it drowns in guesses made from the query's shape.
     */
    public function testErrorsComeBeforeWarnings(): void
    {
        $report = new Report();
        $report->addTrace($this->trace(1, 0), [
            new Finding('query-count', new TestIdentifier('id', 'T::t'), 'a guess', Severity::Warning),
            new Finding('n-plus-one', new TestIdentifier('id', 'T::t'), 'lazy loading', Severity::Error),
        ]);

        $output = $this->render($report);

        self::assertLessThan(
            strpos($output, '[warning]'),
            strpos($output, '[error]'),
            $output,
        );
    }

    public function testNoticesArePrinted(): void
    {
        $report = new Report();
        $report->addNotice("no ORM adapter installed.\nsecond line");

        $output = $this->render($report);

        self::assertStringContainsString('! no ORM adapter installed.', $output);
        self::assertStringContainsString('    second line', $output);
    }

    public function testStrictModeIsAnnouncedOnlyWhenThereAreFindings(): void
    {
        $clean = new Report();
        $clean->addTrace($this->trace(1, 0), []);

        self::assertStringNotContainsString('strict', $this->render($clean, Mode::Strict));

        $dirty = new Report();
        $dirty->addTrace($this->trace(1, 0), [
            new Finding('query-count', new TestIdentifier('id', 'T::t'), 'message'),
        ]);

        self::assertStringContainsString('strict mode: the run is marked as failed', $this->render($dirty, Mode::Strict));
    }

    /**
     * The summary must agree with the exit code. Below the `fail-on` threshold the
     * finding is still printed — but saying "the run is marked as failed" over a run that
     * then comes back green is worse than saying nothing.
     */
    public function testStrictModeSaysSoWhenNothingReachesTheThreshold(): void
    {
        $report = new Report();
        $report->addTrace($this->trace(1, 0), [
            new Finding('select-star', new TestIdentifier('id', 'T::t'), 'every column', Severity::Info),
        ]);

        $output = $this->render($report, Mode::Strict, failOn: Severity::Warning);

        self::assertStringContainsString('[info] select-star', $output);
        self::assertStringContainsString('strict mode: nothing at or above "warning", so the run stays green', $output);
        self::assertStringNotContainsString('marked as failed', $output);
    }

    public function testAFindingAtTheThresholdStillFailsTheRun(): void
    {
        $report = new Report();
        $report->addTrace($this->trace(1, 0), [
            new Finding('select-star', new TestIdentifier('id', 'T::t'), 'every column', Severity::Info),
            new Finding('duplicate-query', new TestIdentifier('id', 'T::t'), 'five times', Severity::Warning),
        ]);

        self::assertStringContainsString(
            'strict mode: the run is marked as failed',
            $this->render($report, Mode::Strict, failOn: Severity::Warning),
        );
    }

    private function render(Report $report, Mode $mode = Mode::Report, string $basePath = '', Severity $failOn = Severity::Warning): string
    {
        $stream = fopen('php://memory', 'w+b');
        self::assertIsResource($stream);

        (new ConsoleReporter($stream, $basePath, $failOn))->report($report, $mode);

        rewind($stream);
        $output = stream_get_contents($stream);
        fclose($stream);

        return (string) $output;
    }

    private function trace(int $queries, int $fixtureQueries): Trace
    {
        $fixtures = [];

        for ($i = 0; $i < $fixtureQueries; ++$i) {
            $fixtures[] = new QueryEvent('INSERT INTO t VALUES (1)');
        }

        $trace = new Trace(new TestIdentifier('id', 'SomeTest::testSomething'), TestOptions::none(), $fixtures);

        for ($i = 0; $i < $queries; ++$i) {
            $trace->record(new QueryEvent('SELECT 1'));
        }

        return $trace;
    }
}
