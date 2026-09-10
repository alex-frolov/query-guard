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
use QueryGuard\Query\Callsite;
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
        // a plain file where a directory is wanted: `mkdir()` fails on it whoever the
        // process happens to be, while a missing directory is now simply created
        $file = (string) tempnam(sys_get_temp_dir(), 'query-guard-not-a-directory-');
        $path = $file.'/baseline.json';

        try {
            $this->notify($this->adapters(), generated: Baseline::empty(), baselinePath: $path);

            self::assertStringContainsString('could not write the baseline to '.$path, $this->notices());
        } finally {
            @unlink($file);
        }
    }

    /**
     * The other half: a baseline whose directory does not exist yet is written, not
     * refused — `var/` is absent from a fresh checkout on most projects.
     */
    public function testABaselineCreatesTheDirectoryItIsWrittenInto(): void
    {
        $directory = sys_get_temp_dir().'/query-guard-baseline-dir-'.getmypid();
        $path = $directory.'/nested/baseline.json';

        try {
            $this->notify($this->adapters(), generated: Baseline::empty(), baselinePath: $path);

            self::assertStringContainsString('baseline regenerated: '.$path, $this->notices());
            self::assertFileExists($path);
        } finally {
            @unlink($path);
            @rmdir(\dirname($path));
            @rmdir($directory);
        }
    }

    /**
     * A report where most findings name somebody else's file is a report nobody can act
     * on — 38% of them on one real suite, all from two wrapper packages. `skip-paths`
     * has always been the fix; the summary now names what to put in it.
     */
    public function testFindingsPointingIntoVendorAreNamedWithTheirPackages(): void
    {
        for ($i = 0; $i < 8; ++$i) {
            $this->report->addTrace($this->trace(), [$this->findingAt('/app/vendor/acme/repository/src/Eloquent/BaseRepository.php')]);
        }

        for ($i = 0; $i < 4; ++$i) {
            $this->report->addTrace($this->trace(), [$this->findingAt('/app/src/Repository/OrderRepository.php')]);
        }

        $this->notify($this->adapters());

        $notices = $this->notices();

        self::assertStringContainsString('8 of 12 findings point inside vendor/', $notices);
        self::assertStringContainsString('vendor/acme/repository (8)', $notices);
        self::assertStringContainsString('skip-paths', $notices);
    }

    /**
     * A couple of vendor callsites among many is how a suite normally looks; the notice
     * exists for the run whose report is unreadable because of them.
     */
    public function testAFewVendorCallsitesAreNotWorthANotice(): void
    {
        $this->report->addTrace($this->trace(), [$this->findingAt('/app/vendor/acme/repository/src/Eloquent/BaseRepository.php')]);

        for ($i = 0; $i < 11; ++$i) {
            $this->report->addTrace($this->trace(), [$this->findingAt('/app/src/Repository/OrderRepository.php')]);
        }

        $this->notify($this->adapters());

        self::assertStringNotContainsString('point inside vendor/', $this->notices());
    }

    /**
     * Queries made in `setUp()` are kept from the rules; a Laravel suite calls its
     * factories on the first line of the test body instead, where that boundary does not
     * reach. When most of the report is about those, the summary points at
     * `fixture-paths` with the directories already named.
     */
    public function testAReportMostlyAboutFixturesPointsAtFixturePaths(): void
    {
        for ($i = 0; $i < 8; ++$i) {
            $this->report->addTrace($this->trace(), [$this->findingAt('/app/database/factories/SongFactory.php')]);
        }

        for ($i = 0; $i < 4; ++$i) {
            $this->report->addTrace($this->trace(), [$this->findingAt('/app/app/Repositories/SongRepository.php')]);
        }

        $this->notify($this->adapters());

        $notices = $this->notices();

        self::assertStringContainsString('8 of 12 findings are about building fixtures', $notices);
        self::assertStringContainsString('database/factories (8)', $notices);
        self::assertStringContainsString('fixture-paths', $notices);
    }

    public function testAFewFixtureCallsitesAreNotWorthANotice(): void
    {
        $this->report->addTrace($this->trace(), [$this->findingAt('/app/database/migrations/2015_create_songs.php')]);

        for ($i = 0; $i < 11; ++$i) {
            $this->report->addTrace($this->trace(), [$this->findingAt('/app/app/Repositories/SongRepository.php')]);
        }

        $this->notify($this->adapters());

        self::assertStringNotContainsString('about building fixtures', $this->notices());
    }

    /**
     * A Laravel package lays its factories out as `src/Database/Factories`, not as an
     * application's `database/factories`, and this is a substring test rather than a
     * case-insensitive one — a bare `Factories` would also claim a DDD project's domain
     * factories, which are not fixtures at all.
     */
    public function testAPackageLayoutFixtureDirectoryIsRecognisedToo(): void
    {
        for ($i = 0; $i < 8; ++$i) {
            $this->report->addTrace($this->trace(), [$this->findingAt('/app/packages/Acme/Product/src/Database/Factories/ProductFactory.php')]);
        }

        for ($i = 0; $i < 4; ++$i) {
            $this->report->addTrace($this->trace(), [$this->findingAt('/app/packages/Acme/Product/src/Repositories/ProductRepository.php')]);
        }

        $this->notify($this->adapters());

        self::assertStringContainsString('Database/Factories (8)', $this->notices());
    }

    /**
     * A whole path segment, so that a project's own `MigrationsHelper.php` is not read as
     * a directory of migrations.
     */
    public function testAFileMerelyNamedAfterOneOfThoseDirectoriesIsNotCounted(): void
    {
        for ($i = 0; $i < 12; ++$i) {
            $this->report->addTrace($this->trace(), [$this->findingAt('/app/src/Support/MigrationsHelper.php')]);
        }

        $this->notify($this->adapters());

        self::assertStringNotContainsString('about building fixtures', $this->notices());
    }

    /**
     * A finding whose every frame was skipped still prints — but with a rule, a test and
     * a message and no file under them, which is a finding nobody can act on. Saying so
     * is the missing half; dropping it would be the silent degradation this package
     * refuses.
     */
    public function testFindingsWithNoCallsiteAreCounted(): void
    {
        $this->report->addTrace($this->trace(), [$this->findingAt('/app/src/Repository/OrderRepository.php')]);
        $this->report->addTrace($this->trace(), [$this->unlocatableFinding(), $this->unlocatableFinding()]);

        $this->notify($this->adapters());

        $notices = $this->notices();

        self::assertStringContainsString('2 of 3 findings have no call site', $notices);
        self::assertStringContainsString('skip-paths', $notices);
        self::assertStringContainsString('query-in-loop', $notices);
    }

    public function testEveryFindingLocatedIsNotWorthANotice(): void
    {
        $this->report->addTrace($this->trace(), [$this->findingAt('/app/src/Repository/OrderRepository.php')]);

        $this->notify($this->adapters());

        self::assertStringNotContainsString('have no call site', $this->notices());
    }

    /**
     * A baseline from another database is not wrong, but its findings will look new: the
     * same DQL becomes different SQL per dialect and the fingerprint carries that into
     * the signature. Measured on one schema across three platforms, where 2 of 114
     * findings differed that way.
     */
    public function testABaselineFromAnotherPlatformIsFlagged(): void
    {
        $path = sys_get_temp_dir().'/query-guard-platform-'.getmypid().'.json';
        file_put_contents($path, '{"platform": "mysql", "findings": {}}');

        try {
            $this->notify($this->adapters(platform: 'pgsql'), baseline: Baseline::fromFile($path));

            self::assertStringContainsString('the baseline was generated on mysql and this run is on pgsql', $this->notices());
        } finally {
            @unlink($path);
        }
    }

    public function testTheSamePlatformIsNotWorthANotice(): void
    {
        $path = sys_get_temp_dir().'/query-guard-platform-same-'.getmypid().'.json';
        file_put_contents($path, '{"platform": "pgsql", "findings": {}}');

        try {
            $this->notify($this->adapters(platform: 'pgsql'), baseline: Baseline::fromFile($path));

            self::assertStringNotContainsString('the baseline was generated on', $this->notices());
        } finally {
            @unlink($path);
        }
    }

    /**
     * A run that never opened a connection knows no platform, and a baseline written
     * before the field existed carries none. Neither is a mismatch.
     */
    public function testAnUnknownPlatformOnEitherSideIsNotAMismatch(): void
    {
        $path = sys_get_temp_dir().'/query-guard-platform-none-'.getmypid().'.json';
        file_put_contents($path, '{"findings": {}}');

        try {
            $this->notify($this->adapters(platform: 'pgsql'), baseline: Baseline::fromFile($path));
            $this->notify($this->adapters(), baseline: Baseline::fromFile($path));

            self::assertStringNotContainsString('the baseline was generated on', $this->notices());
        } finally {
            @unlink($path);
        }
    }

    /**
     * The platform is stamped on the file it writes, which is what makes the notice
     * above possible on the next run.
     */
    public function testARegeneratedBaselineIsStampedWithThePlatform(): void
    {
        $path = sys_get_temp_dir().'/query-guard-stamped-'.getmypid().'.json';

        try {
            $this->notify($this->adapters(platform: 'mysql'), generated: Baseline::empty(), baselinePath: $path);

            self::assertSame('mysql', Baseline::fromFile($path)->platform());
        } finally {
            @unlink($path);
        }
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

    /**
     * An adapter that collected queries and still has something to say.
     *
     * `installationHint()` covers a run that collected nothing. This is the harder case:
     * the summary is about to look healthy while whole connections went unwatched.
     */
    public function testAnAdapterNoticeIsPrintedEvenWhenCollectionWorked(): void
    {
        $this->collector->beginFixtures();
        $this->collector->record(new QueryEvent('SELECT 1'));

        $this->notify(new AdapterSet([
            new FakeOrmAdapter('fake', true, ['the listener sits on a single connection.']),
        ]));

        self::assertStringContainsString('the listener sits on a single connection.', $this->notices());
    }

    /**
     * A baseline only ever grows otherwise: the finding gets fixed, the entry stays, and
     * from then on the file silences something that no longer exists.
     */
    public function testBaselineEntriesThatSilencedNothingAreReported(): void
    {
        $baseline = Baseline::empty();
        $baseline->add($this->finding());

        $this->notify($this->adapters(), baseline: $baseline);

        self::assertStringContainsString('1 baseline entries silenced nothing', $this->notices());
        self::assertStringContainsString('After a filtered run', $this->notices());
    }

    public function testAFullySpentBaselineIsNotMentioned(): void
    {
        $finding = $this->finding();

        $baseline = Baseline::empty();
        $baseline->add($finding);
        $baseline->contains($finding);

        $this->notify($this->adapters(), baseline: $baseline);

        self::assertStringNotContainsString('silenced nothing', $this->notices());
    }

    /**
     * While the baseline is being regenerated nothing is matched against it, so every
     * entry would look unused.
     */
    public function testRegenerationDoesNotAccuseTheBaselineOfBeingStale(): void
    {
        $baseline = Baseline::empty();
        $baseline->add($this->finding());

        $this->notify($this->adapters(), generated: Baseline::empty(), baseline: $baseline);

        self::assertStringNotContainsString('silenced nothing', $this->notices());
    }

    private function notify(
        AdapterSet $adapters,
        Mode $mode = Mode::Report,
        ?Baseline $generated = null,
        string $baselinePath = '',
        ?Tier2Factory $tier2 = null,
        ?Baseline $baseline = null,
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
            Severity::Warning,
            $baseline,
        ))->notify(Events::executionFinished());
    }

    private function adapters(bool $installed = true, string $platform = ''): AdapterSet
    {
        return new AdapterSet([new FakeOrmAdapter('fake', $installed, [], $platform)]);
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

    private function findingAt(string $file): Finding
    {
        return new Finding(
            rule: 'demo',
            test: new TestIdentifier('id', 'SomeTest::testSomething'),
            message: 'found something',
            callsite: new Callsite($file, 559),
            signature: 'demo|'.$file.'|select 1',
        );
    }

    /**
     * What a rule yields when `CallsiteResolver` found no application frame at all —
     * every one of them skipped. `Finding::signature()` writes `unknown` in the file's
     * place, which is what the baseline then remembers it by.
     */
    private function unlocatableFinding(): Finding
    {
        return new Finding(
            rule: 'demo',
            test: new TestIdentifier('id', 'SomeTest::testSomething'),
            message: 'found something',
            signature: 'demo|unknown|select 1',
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
