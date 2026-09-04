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
use QueryGuard\Report\JsonReporter;
use QueryGuard\Report\Report;
use QueryGuard\TestIdentifier;
use QueryGuard\TestOptions;

/**
 * The console summary is written to be read by a person, which means it will be
 * reworded. Anything automated has to read this instead.
 */
#[CoversClass(JsonReporter::class)]
final class JsonReporterTest extends TestCase
{
    private string $path = '';

    protected function tearDown(): void
    {
        if ('' !== $this->path && is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function testFindingsAndCountersAreWritten(): void
    {
        $report = new Report();
        $report->addNotice('tier 2: 4 plans parsed, 0 failed.');
        $report->addTrace($this->trace(6, 2), [
            new Finding(
                rule: 'n-plus-one',
                test: new TestIdentifier('id', 'OrderTest::testList', 'OrderTest', 'testList'),
                message: 'App\Entity\Order::$items — lazy-loaded association, 10 queries',
                severity: Severity::Error,
                callsite: new Callsite('/app/src/Entity/Order.php', 418),
                count: 10,
                signature: 'n-plus-one|/app/src/Entity/Order.php|select * from items where order_id = ?',
            ),
        ]);

        $decoded = $this->write($report, Mode::Strict);

        self::assertSame('strict', $decoded['mode']);
        self::assertSame('warning', $decoded['fail-on']);
        self::assertTrue($decoded['failing']);
        self::assertSame(
            ['tests' => 1, 'queries' => 6, 'fixture-queries' => 2, 'suppressed' => 0, 'findings' => 1],
            $decoded['summary'],
        );
        self::assertSame(['tier 2: 4 plans parsed, 0 failed.'], $decoded['notices']);

        $finding = self::firstFinding($decoded);

        self::assertSame('n-plus-one', $finding['rule']);
        self::assertSame('error', $finding['severity']);
        self::assertSame('OrderTest::testList', $finding['test']);
        self::assertSame('OrderTest', $finding['class']);
        self::assertSame('testList', $finding['method']);
        self::assertSame(418, $finding['line']);
        self::assertSame(10, $finding['count']);
    }

    /**
     * The file is meant to travel to CI, where the project root is a different string.
     */
    public function testPathsAreRelativeToTheProjectRoot(): void
    {
        $report = new Report();
        $report->addTrace($this->trace(1, 0), [
            new Finding(
                rule: 'n-plus-one',
                test: new TestIdentifier('id', 'T::t'),
                message: 'm',
                callsite: new Callsite('/app/src/Entity/Order.php', 418),
                signature: 'n-plus-one|/app/src/Entity/Order.php|select ?',
            ),
        ]);

        $finding = self::firstFinding($this->write($report));

        self::assertSame('src/Entity/Order.php', $finding['file']);
        self::assertSame('n-plus-one|src/Entity/Order.php|select ?', $finding['signature']);
    }

    /**
     * `report` mode never reports itself as failing, whatever was found.
     */
    public function testReportModeIsNeverFailing(): void
    {
        $report = new Report();
        $report->addTrace($this->trace(1, 0), [
            new Finding('n-plus-one', new TestIdentifier('id', 'T::t'), 'm', Severity::Error),
        ]);

        self::assertFalse($this->write($report)['failing']);
    }

    /**
     * A report nobody could write has to be said out loud — which is why this reporter
     * runs before the console one.
     */
    public function testAnUnwritableReportBecomesANotice(): void
    {
        $report = new Report();

        (new JsonReporter('/no/such/directory/report.json'))->report($report, Mode::Report);

        self::assertSame(['could not write the JSON report to /no/such/directory/report.json.'], $report->notices());
    }

    /**
     * The suite is analysed at level 9, and a decoded report is `mixed` all the way
     * down. Narrowing happens here rather than at every assertion.
     *
     * @param array<string, mixed> $decoded
     *
     * @return array<string, mixed>
     */
    private static function firstFinding(array $decoded): array
    {
        $findings = $decoded['findings'] ?? null;
        self::assertIsArray($findings);

        $finding = $findings[0] ?? null;
        self::assertIsArray($finding);

        /* @var array<string, mixed> $finding */
        return $finding;
    }

    /**
     * @return array<string, mixed>
     */
    private function write(Report $report, Mode $mode = Mode::Report): array
    {
        $this->path = tempnam(sys_get_temp_dir(), 'qg') ?: '';

        (new JsonReporter($this->path, '/app'))->report($report, $mode);

        $raw = file_get_contents($this->path);
        self::assertIsString($raw);

        $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /* @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function trace(int $queries, int $fixtureQueries): Trace
    {
        $fixtures = array_fill(0, $fixtureQueries, new QueryEvent('SELECT 1'));

        $trace = new Trace(new TestIdentifier('id', 'T::t'), TestOptions::none(), $fixtures);

        for ($i = 0; $i < $queries; ++$i) {
            $trace->record(new QueryEvent('SELECT 1'));
        }

        return $trace;
    }
}
