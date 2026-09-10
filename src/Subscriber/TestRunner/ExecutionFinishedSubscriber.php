<?php

declare(strict_types=1);

namespace QueryGuard\Subscriber\TestRunner;

use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Event\TestRunner\ExecutionFinishedSubscriber as PHPUnitExecutionFinishedSubscriber;
use QueryGuard\Adapter\AdapterSet;
use QueryGuard\Baseline\Baseline;
use QueryGuard\Collector\DefaultQueryCollector;
use QueryGuard\ExtensionConfiguration;
use QueryGuard\Finding\Severity;
use QueryGuard\Mode;
use QueryGuard\Report\Report;
use QueryGuard\Report\Reporter;
use QueryGuard\Rule\Tier2Factory;

/**
 * The end-of-run summary and the exit code.
 */
final class ExecutionFinishedSubscriber implements PHPUnitExecutionFinishedSubscriber
{
    public function __construct(
        private readonly Report $report,
        private readonly Reporter $reporter,
        private readonly DefaultQueryCollector $collector,
        private readonly AdapterSet $adapters,
        private readonly Mode $mode,
        private readonly ?Baseline $generated = null,
        private readonly string $baselinePath = '',
        private readonly ?Tier2Factory $tier2 = null,
        private readonly Severity $failOn = Severity::Warning,
        private readonly ?Baseline $baseline = null,
    ) {
    }

    /**
     * Baseline entries that silenced nothing.
     *
     * A baseline only ever grows otherwise: the finding gets fixed, the entry stays, and
     * from then on the file quietly silences something that no longer exists — including,
     * eventually, a regression that lands on the same rule in the same file.
     *
     * Deliberately not phrased as "obsolete". After `--filter`, or a run that excluded a
     * group, an unmatched entry only means its test did not run, and a tool that called
     * that obsolete would be teaching people to delete live entries.
     *
     * @return list<string>
     */
    private function baselineNotices(): array
    {
        // in regeneration mode nothing is matched against, so everything would look unused
        if (null !== $this->generated || null === $this->baseline) {
            return [];
        }

        $unmatched = $this->baseline->unmatched();

        if ([] === $unmatched) {
            return [];
        }

        return [sprintf(
            "%d baseline entries silenced nothing in this run.\n"
            ."After a full-suite run they are obsolete: regenerate with %s=1 to drop them.\n"
            .'After a filtered run it only means those tests did not execute.',
            \count($unmatched),
            ExtensionConfiguration::GENERATE_BASELINE_ENV,
        )];
    }

    /**
     * A refusal has to be loud. A green summary over an empty trace is exactly how a
     * tool loses trust. Three different kinds of "nothing was collected" are told apart:
     * no ORM at all, an ORM whose interception failed, and interception that worked but
     * saw no queries.
     *
     * @return list<string>
     */
    private function adapterNotices(): array
    {
        if ($this->collector->totalRecorded() > 0) {
            // something is feeding the collector — nothing to complain about
            return [];
        }

        if ($this->adapters->isEmpty()) {
            return ['neither Doctrine nor Eloquent was found in this project — nothing to collect queries with.'];
        }

        $notInstalled = $this->adapters->notInstalled();

        if ([] !== $notInstalled) {
            return [sprintf(
                "an ORM was found (%s) but interception did not take, and no queries were collected.\n%s",
                implode(', ', $notInstalled),
                implode("\n", $this->adapters->installationHints()),
            )];
        }

        return [sprintf(
            "the adapter (%s) is in place, but not a single query ran during the whole suite.\n"
            .'The rules checked nothing; this is not "all clear", it is "nothing to look at".',
            implode(', ', $this->adapters->names()),
        )];
    }

    /**
     * When most findings point at somebody else's code.
     *
     * The callsite is the first application frame, and the skip list knows about DBAL,
     * PDO and the insides of both ORMs — but not about the packages a project wraps them
     * in. On a real suite where every repository extended a base class out of a vendored
     * package, 38% of findings (4 883 of 12 813) named a file inside `vendor/`: a place
     * the developer cannot fix and would not think to look at.
     *
     * `skip-paths` has always been the answer, and this is the part that was missing —
     * saying so, with the packages already named so they can be pasted in. Naming them
     * beats extending the built-in list with wrapper packages: there are dozens, the list
     * would go stale by the first release, and a project installed by path legitimately
     * lives under `vendor/` itself.
     *
     * A third is the point where this stops being an oddity and starts being how the
     * report reads; under ten findings the share means little either way.
     *
     * @return list<string>
     */
    private function vendorCallsiteNotices(): array
    {
        $findings = $this->report->findings();
        $packages = [];
        $inVendor = 0;

        foreach ($findings as $finding) {
            $package = null === $finding->callsite ? null : self::vendorPackage($finding->callsite->file);

            if (null === $package) {
                continue;
            }

            ++$inVendor;
            $packages[$package] = ($packages[$package] ?? 0) + 1;
        }

        if (\count($findings) < 10 || $inVendor * 3 < \count($findings)) {
            return [];
        }

        arsort($packages);
        $named = \array_slice($packages, 0, 3, true);
        $listed = [];

        foreach ($named as $package => $count) {
            $listed[] = sprintf('%s (%d)', $package, $count);
        }

        return [sprintf(
            "%d of %d findings point inside vendor/: %s.\n"
            .'No #[IgnoreRule] fixes a finding whose file is not yours — add those to "skip-paths" '
            .'and the callsite moves to the first frame in your own code.',
            $inVendor,
            \count($findings),
            implode(', ', $listed),
        )];
    }

    /**
     * Directories both supported frameworks keep stand preparation in.
     *
     * Not a guess about an unknown project: Doctrine and Eloquent are the two ORMs this
     * package supports, and these are where each of them puts migrations, factories and
     * fixtures by convention. A path is only ever *suggested* from this list — nothing is
     * filtered on the strength of it.
     *
     * @var list<string>
     */
    private const FIXTURE_DIRECTORIES = [
        'database/migrations',
        'database/factories',
        'database/seeders',
        // the same three as a Laravel *package* lays them out — `src/Database/Factories`
        // rather than an application's `database/factories`. Both Webkul projects the
        // package has been run against are built that way, and the lowercase entries do
        // not match them: this is a substring test, not a case-insensitive one, because
        // a bare `Factories` would also claim a DDD project's domain factories
        'Database/Migrations',
        'Database/Factories',
        'Database/Seeders',
        'Migrations',
        'DataFixtures',
    ];

    /**
     * When a large share of the report is about building the stand.
     *
     * A finding on a factory is true and useless: the factory really did issue fifty
     * inserts from one line, and nobody is going to change that. Queries made in `setUp()`
     * are kept out of the rules for exactly this reason — but a Laravel suite calls its
     * factories on the first line of the test body instead, where that rule does not
     * reach. On one 1 617-test suite that was 64% of the report.
     *
     * `fixture-paths` is the answer and this is the pointer to it, with the directories
     * already named so they can be pasted in. The share threshold is `vendorCallsiteNotices()`'s,
     * deliberately the same number: both notices say "the report has stopped being about
     * your application", and giving them two different definitions of "mostly" would be
     * inventing a constant to no end.
     *
     * @return list<string>
     */
    private function fixtureCallsiteNotices(): array
    {
        $findings = $this->report->findings();
        $directories = [];
        $inFixtures = 0;

        foreach ($findings as $finding) {
            $directory = null === $finding->callsite ? null : self::fixtureDirectory($finding->callsite->file);

            if (null === $directory) {
                continue;
            }

            ++$inFixtures;
            $directories[$directory] = ($directories[$directory] ?? 0) + 1;
        }

        if (\count($findings) < 10 || $inFixtures * 3 < \count($findings)) {
            return [];
        }

        arsort($directories);
        $listed = [];

        foreach ($directories as $directory => $count) {
            $listed[] = sprintf('%s (%d)', $directory, $count);
        }

        return [sprintf(
            "%d of %d findings are about building fixtures, not about the application: %s.\n"
            .'Queries made in setUp() are already left out of the rules; these were made from the test body, where that does not reach. '
            .'Put those directories in "fixture-paths" and they move to the same bucket — counted in "in setUp", examined by nothing.',
            $inFixtures,
            \count($findings),
            implode(', ', $listed),
        )];
    }

    /**
     * `/app/database/factories/SongFactory.php` becomes `database/factories` — the exact
     * string `fixture-paths` wants. Matched on a whole segment so that a project file
     * called `MigrationsHelper.php` is not mistaken for a directory of migrations.
     */
    private static function fixtureDirectory(string $file): ?string
    {
        $normalized = str_replace('\\', '/', $file);

        foreach (self::FIXTURE_DIRECTORIES as $directory) {
            if (str_contains($normalized, '/'.$directory.'/')) {
                return $directory;
            }
        }

        return null;
    }

    /**
     * Findings the reader cannot go and look at.
     *
     * A callsite is the first application frame in the stack, and there is not always
     * one: every frame can be skipped — by the built-in list, by `skip-paths`, by
     * `skip-functions` — and then the finding is real but has nowhere to point. It still
     * gets printed, with a rule, a test and a message and no file under them, and its
     * baseline signature says `unknown` in place of the path.
     *
     * Printing it is the right call: dropping a real finding because it could not be
     * located is the silent degradation this package exists to refuse, and the signature
     * would take other people's baseline entries with it. What was missing is saying so.
     * Measured on a Laravel suite where `skip-paths` had been pointed at `tests/` as well
     * as the fixture directories: 757 of 1862 findings (41%) came back with no callsite
     * at all, and nothing in the run said why.
     *
     * `query-in-loop` is named because it behaves differently on purpose: it groups by
     * callsite, so a query without one cannot be grouped and is dropped before the
     * threshold is applied. That is a rule with no answer rather than a rule staying
     * quiet, and it is worth knowing that those queries went unexamined.
     *
     * @return list<string>
     */
    private function unlocatableNotices(): array
    {
        $findings = $this->report->findings();
        $unlocatable = 0;

        foreach ($findings as $finding) {
            if (null === $finding->callsite) {
                ++$unlocatable;
            }
        }

        if (0 === $unlocatable) {
            return [];
        }

        return [sprintf(
            "%d of %d findings have no call site: every frame of their stack was skipped, so there is no file to point at.\n"
            .'That is usually "skip-paths" or "skip-functions" reaching too far — a path holding your own test or fixture code '
            ."cannot be stepped over and still be named.\n"
            .'Queries in that state are also invisible to "query-in-loop", which groups by call site.',
            $unlocatable,
            \count($findings),
        )];
    }

    /**
     * `/app/vendor/acme/repository/src/Eloquent/BaseRepository.php` becomes
     * `vendor/acme/repository` — the exact string `skip-paths` wants.
     */
    private static function vendorPackage(string $file): ?string
    {
        $normalized = str_replace('\\', '/', $file);
        $position = strrpos($normalized, '/vendor/');

        if (false === $position) {
            return null;
        }

        $parts = explode('/', substr($normalized, $position + 8));

        return \count($parts) < 3 ? null : 'vendor/'.$parts[0].'/'.$parts[1];
    }

    /**
     * The platforms this run collected on, as `PlatformDriver` names them.
     *
     * Taken from the adapters' explainers, which know it whether or not tier 2 is on. A
     * run that never opened a connection reports nothing rather than guessing.
     */
    private function platform(): string
    {
        $platforms = [];

        foreach ($this->adapters->explainers() as $explainer) {
            $platforms[$explainer->platform()] = true;
        }

        unset($platforms['']);
        ksort($platforms);

        return implode(', ', array_keys($platforms));
    }

    /**
     * A baseline generated on another database is not wrong, but it will look it.
     *
     * See `Baseline::platform()` for the measurement behind this: the same DQL becomes
     * different SQL per dialect, the fingerprint carries that difference into the
     * signature, and a project moving between databases then sees known findings arrive
     * as new ones. Rare, and impossible to guess from the outside — hence a notice
     * rather than silence.
     *
     * @return list<string>
     */
    private function platformNotices(): array
    {
        $baseline = null === $this->generated ? $this->baseline : null;
        $was = $baseline?->platform() ?? '';
        $now = $this->platform();

        if ('' === $was || '' === $now || $was === $now) {
            return [];
        }

        return [sprintf(
            "the baseline was generated on %s and this run is on %s.\n"
            .'The same query can carry a different fingerprint on another platform (CONCAT against ||, CAST types), '
            .'so a known finding may arrive as a new one — regenerate the baseline on the platform CI runs.',
            $was,
            $now,
        )];
    }

    public function notify(ExecutionFinished $event): void
    {
        foreach ($this->adapterNotices() as $notice) {
            $this->report->addNotice($notice);
        }

        // said even when collection worked: a trace missing whole connections and a
        // complete one both end in the same "no findings" line otherwise
        foreach ($this->adapters->notices() as $notice) {
            $this->report->addNotice($notice);
        }

        foreach ($this->tier2?->notices() ?? [] as $notice) {
            $this->report->addNotice($notice);
        }

        foreach ($this->baselineNotices() as $notice) {
            $this->report->addNotice($notice);
        }

        foreach ($this->vendorCallsiteNotices() as $notice) {
            $this->report->addNotice($notice);
        }

        foreach ($this->fixtureCallsiteNotices() as $notice) {
            $this->report->addNotice($notice);
        }

        foreach ($this->unlocatableNotices() as $notice) {
            $this->report->addNotice($notice);
        }

        foreach ($this->platformNotices() as $notice) {
            $this->report->addNotice($notice);
        }

        if ($this->collector->droppedOutsideTests() > 0) {
            $this->report->addNotice(sprintf(
                '%d queries ran outside the boundaries of a test (bootstrap, data providers) and were not passed to the rules.',
                $this->collector->droppedOutsideTests(),
            ));
        }

        if (null !== $this->generated && '' !== $this->baselinePath) {
            $this->report->addNotice(
                $this->generated->save($this->baselinePath, $this->platform())
                    ? sprintf('baseline regenerated: %s, %d findings inside.', $this->baselinePath, $this->generated->count())
                    : sprintf('could not write the baseline to %s.', $this->baselinePath),
            );
        }

        $this->reporter->report($this->report, $this->mode);

        // everything found is printed; only what reaches the `fail-on` severity fails the
        // run. `select-star` is `info` and fires on every Eloquent query there is
        if (null === $this->generated && Mode::Strict === $this->mode && $this->report->hasFindingsAtLeast($this->failOn)) {
            // PHPUnit's event system lets an extension neither fail a test nor change
            // the exit code. The only point that works is shutdown: it runs after
            // PHPUnit has already returned its own code — and therefore replaces it.
            // When PHPUnit is failing the run anyway, its code is the more specific one
            // (2 for errors, 1 for failures) and is left alone.
            if ($this->report->isRunnerFailing()) {
                return;
            }

            register_shutdown_function(static function (): void {
                exit(1);
            });
        }
    }
}
