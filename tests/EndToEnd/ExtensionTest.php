<?php

declare(strict_types=1);

namespace QueryGuard\Test\EndToEnd;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Event wiring cannot be covered by a unit test: the extension lives inside the runner.
 * So a real PHPUnit is launched over a fixture suite here, and its output and exit code
 * are what gets asserted.
 */
#[CoversNothing]
final class ExtensionTest extends TestCase
{
    /**
     * Where `Test\Prepared` falls relative to `setUp()`.
     *
     * The fixture makes 3 queries in `setUp()` and 2 in the test body. Had the trace
     * opened on `PreparationStarted`, all 5 would have landed in it.
     */
    public function testTraceStartsAfterSetUp(): void
    {
        [$output] = $this->runFixture('phpunit.xml');

        self::assertStringContainsString('tests traced: 6, queries: 28 (in setUp: 3)', $output, $output);
    }

    public function testFindingIsReportedWithCallsiteInApplicationCode(): void
    {
        [$output] = $this->runFixture('phpunit.xml');

        self::assertStringContainsString('query-count — QueryGuard\Test\EndToEnd\Fixture\Traced\ThresholdTest::testOverTheThreshold', $output, $output);
        self::assertStringContainsString('5 queries against a budget of 2', $output, $output);
        self::assertStringContainsString('tests/EndToEnd/Fixture/Traced/ThresholdTest.php:', $output, $output);

        // the callsite must point at application code, not inside the package — exactly
        // where a competitor goes wrong
        self::assertStringNotContainsString('src/Collector/', $output, $output);
        self::assertStringNotContainsString('src/Query/', $output, $output);
    }

    public function testBoundaryTestStaysUnderTheThresholdBecauseFixturesAreNotCounted(): void
    {
        [$output] = $this->runFixture('phpunit.xml');

        // 3 queries in setUp() plus 2 in the body, budget 2. A finding would only appear
        // if the fixture queries had landed in the trace.
        self::assertStringNotContainsString('BoundaryTest', $output, $output);
    }

    public function testAllowQueriesAttributeRaisesTheThreshold(): void
    {
        [$output] = $this->runFixture('phpunit.xml');

        self::assertStringNotContainsString('testAllowQueriesRaisesTheThreshold', $output, $output);
    }

    public function testIgnoreRuleAttributeSilencesTheRule(): void
    {
        [$output] = $this->runFixture('phpunit.xml');

        self::assertStringNotContainsString('testIgnoreRuleSilencesTheRule', $output, $output);
    }

    public function testNPlusOneIsFoundAndDuplicatesAreNot(): void
    {
        [$output] = $this->runFixture('phpunit.xml');

        self::assertStringContainsString('n-plus-one — QueryGuard\Test\EndToEnd\Fixture\Traced\NPlusOneTest::testClassicNPlusOne', $output, $output);
        self::assertStringContainsString('5 queries of the same shape from one place, different values', $output, $output);
        self::assertStringContainsString('SELECT * FROM customers WHERE id = ?', $output, $output);

        // repeats with identical values are a duplicate; another rule handles those
        self::assertStringNotContainsString('n-plus-one — QueryGuard\Test\EndToEnd\Fixture\Traced\NPlusOneTest::testIdenticalRepeatsAreNotNPlusOne', $output, $output);
    }

    public function testTier1RulesFire(): void
    {
        [$output] = $this->runFixture('phpunit-tier1.xml');

        self::assertStringContainsString('[warning] duplicate-query', $output, $output);
        self::assertStringContainsString('the same query with the same values ran 3 times', $output, $output);
        self::assertStringContainsString('[warning] query-in-loop', $output, $output);
        self::assertStringContainsString('5 queries of 5 different shapes from one place', $output, $output);
        self::assertStringContainsString('[warning] no-limit', $output, $output);
        self::assertStringContainsString('"invoices"', $output, $output);
        self::assertStringContainsString('[info] select-star', $output, $output);
    }

    /**
     * The key property of a baseline: what is old stays quiet, but the summary states
     * honestly how much was silenced. "No findings" must not mean two different things.
     */
    public function testBaselineSilencesKnownFindingsButSaysHowMany(): void
    {
        [$output, $exitCode] = $this->runFixture('phpunit-tier1-baseline.xml');

        self::assertStringContainsString('silenced by baseline: 7', $output, $output);
        self::assertStringContainsString('no findings', $output, $output);
        self::assertSame(0, $exitCode, $output);
    }

    public function testBaselineIsRegeneratedOnDemand(): void
    {
        $baseline = sys_get_temp_dir().'/query-guard-baseline-'.getmypid().'.json';
        $configuration = $this->temporaryTier1Configuration($baseline);

        try {
            [$output] = $this->runConfiguration($configuration, ['QUERY_GUARD_GENERATE_BASELINE' => '1']);

            self::assertStringContainsString('baseline regenerated', $output, $output);
            self::assertFileExists($baseline);

            $decoded = json_decode((string) file_get_contents($baseline), true);

            self::assertIsArray($decoded);
            self::assertCount(7, $decoded['findings']);
        } finally {
            @unlink($baseline);
            @unlink($configuration);
        }
    }

    private function temporaryTier1Configuration(string $baseline): string
    {
        $path = sys_get_temp_dir().'/query-guard-tier1-'.getmypid().'.xml';

        file_put_contents($path, sprintf(
            '<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="%s" cacheDirectory="%s" colors="false">
    <testsuites>
        <testsuite name="fixture"><directory>%s</directory></testsuite>
    </testsuites>
    <extensions>
        <bootstrap class="QueryGuard\Extension">
            <parameter name="select-star" value="true"/>
            <parameter name="large-tables" value="invoices, orders"/>
            <parameter name="query-in-loop-threshold" value="5"/>
            <parameter name="duplicate-threshold" value="3"/>
            <parameter name="baseline" value="%s"/>
        </bootstrap>
    </extensions>
</phpunit>',
            \dirname(__DIR__, 2).'/vendor/autoload.php',
            sys_get_temp_dir().'/query-guard-cache-'.getmypid(),
            __DIR__.'/Fixture/Tier1',
            $baseline,
        ));

        return $path;
    }

    /**
     * Tier 2 is enabled but there is no connection. The summary has to say so plainly:
     * "no plan rules ran" and "all clear" are different things.
     */
    public function testTier2SaysWhenItHadNothingToLookAt(): void
    {
        [$output] = $this->runFixture('phpunit-tier2.xml');

        self::assertStringContainsString('tier 2 is enabled but no database connection appeared', $output, $output);
    }

    public function testReportModeKeepsTheRunGreen(): void
    {
        [$output, $exitCode] = $this->runFixture('phpunit.xml');

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('OK', $output, $output);
    }

    public function testStrictModeFailsTheRun(): void
    {
        [$output, $exitCode] = $this->runFixture('phpunit-strict.xml');

        self::assertStringContainsString('strict mode: the run is marked as failed', $output, $output);
        self::assertSame(1, $exitCode, $output);
    }

    public function testSilentRunSaysThereWasNoAdapter(): void
    {
        [$output] = $this->runFixture('phpunit-silent.xml');

        // both ORMs are in vendor (require-dev) but interception did not take — the
        // summary must tell "no ORM", "interception failed" and "no queries" apart
        self::assertStringContainsString('an ORM was found (doctrine, eloquent) but interception did not take', $output, $output);
        self::assertStringContainsString('doctrine.middleware', $output, $output);
    }

    /**
     * A real DBAL middleware inside a real run: 6 queries in `setUp()` (DDL plus 5
     * inserts) and 5 lazy fetches in the test body.
     */
    public function testDoctrineMiddlewareFeedsTheTraceInsideARealRun(): void
    {
        [$output] = $this->runFixture('phpunit-doctrine.xml');

        self::assertStringContainsString('tests traced: 1, queries: 5 (in setUp: 6)', $output, $output);
        self::assertStringContainsString('query-count — QueryGuard\Test\EndToEnd\Fixture\Doctrine\DoctrineTracedTest::testNPlusOneShape', $output, $output);
        self::assertStringContainsString('5 queries against a budget of 2', $output, $output);
        self::assertStringContainsString('tests/EndToEnd/Fixture/Doctrine/DoctrineTracedTest.php:', $output, $output);
        self::assertStringNotContainsString('interception did not take', $output, $output);
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function runFixture(string $configuration, array $environment = []): array
    {
        return $this->runConfiguration(__DIR__.'/Fixture/'.$configuration, $environment);
    }

    /**
     * @param array<string, string> $environment
     *
     * @return array{0: string, 1: int}
     */
    private function runConfiguration(string $path, array $environment = []): array
    {
        $prefix = '';

        foreach ($environment as $name => $value) {
            $prefix .= $name.'='.escapeshellarg($value).' ';
        }

        $command = sprintf(
            '%s%s %s --configuration %s 2>&1',
            $prefix,
            escapeshellarg(\PHP_BINARY),
            escapeshellarg(\dirname(__DIR__, 2).'/vendor/phpunit/phpunit/phpunit'),
            escapeshellarg($path),
        );

        $lines = [];
        $exitCode = 0;
        exec($command, $lines, $exitCode);

        return [implode(\PHP_EOL, $lines), $exitCode];
    }
}
