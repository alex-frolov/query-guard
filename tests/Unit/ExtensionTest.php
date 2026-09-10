<?php

declare(strict_types=1);

namespace QueryGuard\Test\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Registry;
use QueryGuard\Collector\DefaultQueryCollector;
use QueryGuard\Collector\Phase;
use QueryGuard\Extension;
use QueryGuard\ExtensionConfiguration;
use QueryGuard\QueryGuard;
use QueryGuard\Subscriber\Test\ErroredSubscriber;
use QueryGuard\Subscriber\Test\FailedSubscriber;
use QueryGuard\Subscriber\Test\FinishedSubscriber;
use QueryGuard\Subscriber\Test\PreparationStartedSubscriber;
use QueryGuard\Subscriber\Test\PreparedSubscriber;
use QueryGuard\Subscriber\TestRunner\ExecutionFinishedSubscriber;
use QueryGuard\Test\Unit\Fixture\Events;
use QueryGuard\Test\Unit\Fixture\RecordingFacade;
use QueryGuard\Test\Unit\Fixture\SealedFacade;

/**
 * What the entry point builds out of a handful of parameters.
 *
 * The configuration is the real one of the run in progress — `Configuration` is a final
 * value object with a constructor that changes shape between PHPUnit versions, and the
 * two questions the extension asks it (`noOutput()`, `outputToStandardErrorStream()`) are
 * answered the same way by the real one.
 *
 * The facade cannot be substituted before PHPUnit 13, where it became an interface: until
 * then it is a final class writing straight into an event system that is sealed long
 * before any test runs. Everything here therefore skips below 13.
 */
#[CoversClass(Extension::class)]
final class ExtensionTest extends TestCase
{
    protected function tearDown(): void
    {
        // the extension activates a global collector; the next test must not inherit it
        QueryGuard::deactivate();
    }

    /**
     * The collector has to be reachable through `QueryGuard` and nowhere else: a DBAL
     * middleware is built by the application's container, where nothing can be injected.
     */
    public function testBootstrapActivatesTheCollectorAdaptersWriteTo(): void
    {
        self::assertFalse(QueryGuard::isActive());

        $this->registeredSubscribers(['mode' => 'report']);

        self::assertTrue(QueryGuard::isActive());
        self::assertInstanceOf(DefaultQueryCollector::class, QueryGuard::collector());
    }

    /**
     * A process whose event system is already sealed gets no subscribers, and must get no
     * exception either.
     *
     * ParaTest's parent bootstraps the extensions from `SuiteLoader`, which under Pest
     * runs after the facade has been sealed. Letting that escape turned every
     * `pest --parallel` run into exit code 1: PHPUnit records the failed bootstrap as a
     * test-runner warning and Pest fails a run on those by default — with the suite green,
     * every worker's report correctly written, and nothing in the output to explain it.
     *
     * Standing down is right rather than merely quiet: that process runs no tests. The
     * workers bootstrap this extension again on facades of their own, and they are the
     * ones that collect.
     */
    public function testASealedEventFacadeStandsTheExtensionDown(): void
    {
        if (!interface_exists(Facade::class)) {
            self::markTestSkipped('PHPUnit below 13 has a final extension facade that cannot be substituted.');
        }

        (new Extension())->bootstrap(Registry::get(), new SealedFacade(), ParameterCollection::fromArray(['mode' => 'report']));

        self::assertFalse(QueryGuard::isActive(), 'a process that could not subscribe must not be left with a live collector');
    }

    public function testEverySubscriberIsRegistered(): void
    {
        $subscribers = $this->registeredSubscribers(['mode' => 'strict', 'max-queries' => '5']);

        self::assertSame(
            [
                PreparationStartedSubscriber::class,
                PreparedSubscriber::class,
                FinishedSubscriber::class,
                FailedSubscriber::class,
                ErroredSubscriber::class,
                ExecutionFinishedSubscriber::class,
            ],
            array_map(static fn (object $subscriber): string => $subscriber::class, $subscribers),
        );
    }

    /**
     * The wiring only works if the subscribers and the adapters end up on the same
     * collector — an extension that registers subscribers over a collector of its own
     * would report every suite as having run no queries at all.
     */
    public function testTheRegisteredSubscribersDriveTheActiveCollector(): void
    {
        $subscribers = $this->registeredSubscribers([]);
        $collector = QueryGuard::collector();

        self::assertInstanceOf(DefaultQueryCollector::class, $collector);
        self::assertInstanceOf(PreparationStartedSubscriber::class, $subscribers[0]);
        self::assertInstanceOf(PreparedSubscriber::class, $subscribers[1]);

        $subscribers[0]->notify(Events::preparationStarted());
        self::assertSame(Phase::Fixtures, $collector->phase());

        $subscribers[1]->notify(Events::prepared());
        self::assertSame(Phase::Test, $collector->phase());
    }

    /**
     * Bootstrapping must not throw, whatever it is handed. An exception here takes the
     * whole suite down before a single test has run, and what the developer then sees
     * looks like anything but a query-guard problem.
     *
     * The sets below are the ones with something to build: tier 2's object graph, a
     * baseline path to resolve, a parameter that could not be understood and has to be
     * carried into the summary as a notice. Windows spellings are among them because the
     * package normalises paths elsewhere, and a `C:\` baseline must not end up appended
     * to the working directory.
     *
     * @param array<string, string> $parameters
     */
    #[DataProvider('parameterSets')]
    public function testBootstrapWiresUpWhateverTheParameters(array $parameters): void
    {
        self::assertCount(6, $this->registeredSubscribers($parameters));
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function parameterSets(): iterable
    {
        yield 'nothing configured' => [[]];
        yield 'a value that could not be understood' => [['mode' => 'strickt', 'max-queries' => 'lots']];
        yield 'tier 2' => [['tier2' => 'true', 'min-rows' => '500']];
        yield 'a relative baseline' => [['baseline' => 'tests/EndToEnd/Fixture/Tier1/baseline.json']];
        yield 'an absolute baseline' => [['baseline' => '/tmp/query-guard.json']];
        yield 'a Windows baseline' => [['baseline' => 'C:\Temp\query-guard.json']];
        yield 'a UNC baseline' => [['baseline' => '\\\\share\query-guard.json']];
    }

    /**
     * The environment variable alone writes nothing: without a `baseline` parameter there
     * is nowhere to write, and a run that quietly did nothing looks exactly like a run
     * that succeeded.
     */
    public function testAskingForABaselineWithNowhereToWriteItStillWiresUp(): void
    {
        $_ENV[ExtensionConfiguration::GENERATE_BASELINE_ENV] = '1';

        try {
            self::assertCount(6, $this->registeredSubscribers([]));
            self::assertCount(6, $this->registeredSubscribers(['baseline' => 'var/query-guard.json']));
        } finally {
            unset($_ENV[ExtensionConfiguration::GENERATE_BASELINE_ENV]);
        }
    }

    /**
     * A relative "baseline"/"report-json" that climbs out of the project via ".." is
     * more likely a stray CI substitution than an intended destination. Silently
     * reading from, or writing to, wherever that lands is exactly the kind of quiet
     * misbehaviour this package exists to call out.
     */
    public function testAPathThatClimbsOutOfTheProjectIsFlaggedInTheSummary(): void
    {
        $report = $this->reportFor(['baseline' => '../../etc/cron.d/query-guard']);

        self::assertNotSame([], $report->notices());
        self::assertStringContainsString('climbs out of the project', implode("\n", $report->notices()));
    }

    /**
     * A relative path that stays inside the project — however it is spelled — must not
     * be flagged: only an actual escape is worth a word.
     */
    public function testARelativePathThatStaysInsideTheProjectIsNotFlagged(): void
    {
        self::assertSame([], $this->reportFor(['baseline' => 'var/sub/../query-guard.json'])->notices());
    }

    /**
     * @param array<string, string> $parameters
     */
    private function reportFor(array $parameters): \QueryGuard\Report\Report
    {
        $subscribers = $this->registeredSubscribers($parameters);
        $property = new \ReflectionProperty($subscribers[\count($subscribers) - 1], 'report');
        $report = $property->getValue($subscribers[\count($subscribers) - 1]);

        self::assertInstanceOf(\QueryGuard\Report\Report::class, $report);

        return $report;
    }

    /**
     * @param array<string, string> $parameters
     *
     * @return list<object>
     */
    private function registeredSubscribers(array $parameters): array
    {
        if (!interface_exists(Facade::class)) {
            self::markTestSkipped('PHPUnit below 13 has a final extension facade that cannot be substituted.');
        }

        $facade = new RecordingFacade();

        (new Extension())->bootstrap(Registry::get(), $facade, ParameterCollection::fromArray($parameters));

        return $facade->subscribers;
    }
}
