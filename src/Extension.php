<?php

declare(strict_types=1);

namespace QueryGuard;

use PHPUnit\Event\EventFacadeIsSealedException;
use PHPUnit\Runner\Extension\Extension as PHPUnitExtension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;
use QueryGuard\Adapter\AdapterSet;
use QueryGuard\Baseline\Baseline;
use QueryGuard\Collector\DefaultQueryCollector;
use QueryGuard\Collector\FixtureFilter;
use QueryGuard\Query\CallsiteResolver;
use QueryGuard\Query\QueryEvent;
use QueryGuard\Report\ConsoleReporter;
use QueryGuard\Report\JsonReporter;
use QueryGuard\Report\Report;
use QueryGuard\Report\Reporters;
use QueryGuard\Rule\DuplicateQueryRule;
use QueryGuard\Rule\NoLimitRule;
use QueryGuard\Rule\NPlusOneRule;
use QueryGuard\Rule\QueryCountRule;
use QueryGuard\Rule\QueryInLoopRule;
use QueryGuard\Rule\RuleEngine;
use QueryGuard\Rule\SelectStarRule;
use QueryGuard\Rule\Tier2Factory;
use QueryGuard\Subscriber\Test\ErroredSubscriber;
use QueryGuard\Subscriber\Test\FailedSubscriber;
use QueryGuard\Subscriber\Test\FinishedSubscriber;
use QueryGuard\Subscriber\Test\PreparationStartedSubscriber;
use QueryGuard\Subscriber\Test\PreparedSubscriber;
use QueryGuard\Subscriber\TestRunner\ExecutionFinishedSubscriber;

/**
 * The entry point. The whole installation is two things and not a line inside the tests:
 *
 *     composer require --dev alex-frolov/query-guard
 *
 *     <extensions>
 *         <bootstrap class="QueryGuard\Extension">
 *             <parameter name="mode" value="report"/>
 *         </bootstrap>
 *     </extensions>
 */
final class Extension implements PHPUnitExtension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $config = ExtensionConfiguration::fromParameters($parameters);
        $worker = Worker::fromEnvironment();

        // `--no-output` is read as "the user asked for silence" only when PHPUnit is the
        // one printing — see `printerReplaced()`
        $console = !$configuration->noOutput() || self::printerReplaced();
        $writesFile = '' !== $config->jsonReportPath || ($config->generateBaseline && '' !== $config->baselinePath);

        // the run is left alone only when there is nowhere to say anything at all: no
        // console, no JSON report, no baseline to regenerate. A file is a channel too,
        // and a silenced console is no reason to skip filling one
        if (!$console && !$writesFile) {
            return;
        }

        $stream = $console
            ? fopen($configuration->outputToStandardErrorStream() ? 'php://stderr' : 'php://stdout', 'wb')
            : false;

        if (false === $stream && !$writesFile) {
            // there is nowhere to print the summary, so collecting would only cost
            return;
        }

        $basePath = getcwd() ?: '';

        // Before the collector exists, and therefore before any adapter is installed: a
        // DBAL middleware is built by the application's container, which cannot be handed
        // a configured resolver, and `EloquentAdapter::attach()` builds one of its own the
        // moment it subscribes. Everything downstream reads these statics through
        // `CallsiteResolver::default()`, so they have to be written first.
        CallsiteResolver::configureSkipPaths($config->skipPaths);
        CallsiteResolver::configureSkipFunctions($config->skipFunctions);
        CallsiteResolver::configureChainDepth($config->callChainDepth);

        $callsiteResolver = CallsiteResolver::default();

        // the same resolver the rules use, on purpose: `QueryEvent` memoises a callsite
        // per resolver, so filtering at record time costs the rules nothing later
        $fixtures = new FixtureFilter($config->fixturePaths, $callsiteResolver);

        $collector = new DefaultQueryCollector($config->maxTraceQueries, $fixtures->isEmpty() ? null : $fixtures);
        $adapters = AdapterSet::detect();
        $report = new Report();

        foreach ($config->warnings as $warning) {
            $report->addNotice($warning);
        }

        if ($console && false === $stream) {
            // the summary was meant to be printed and cannot be; the JSON report carries
            // notices, so this one still has somewhere to go
            $report->addNotice('the summary could not be printed: neither php://stdout nor php://stderr could be opened.');
        }

        $tier2 = $config->tier2
            ? new Tier2Factory($adapters, $collector, $callsiteResolver, $config->minRows)
            : null;

        // Eloquent cannot wait for a rule to ask for a plan at `Test\Finished`: by then
        // RefreshDatabase/DatabaseTransactions has already rolled the test's transaction
        // back. Wiring this here, not in EloquentAdapter::install(), keeps the adapter
        // usable with tier 2 off — nothing to call when there is no PlanProvider.
        if (null !== $tier2) {
            $adapters->eloquent()?->enableEagerExplain(static function (QueryEvent $event) use ($tier2, $fixtures): void {
                // the trace drops fixture queries before any rule sees them, but this
                // runs inside the listener, before the collector has bucketed anything —
                // without the same check, tier 2 would EXPLAIN the schema being built and
                // report `no-possible-index` at `error` against a table that gets its
                // index in the next migration
                if ($fixtures->isFixture($event)) {
                    return;
                }

                $tier2->provider()->planFor($event);
            });
        }

        $engine = new RuleEngine([
            new NPlusOneRule($callsiteResolver, $config->nPlusOneThreshold),
            new DuplicateQueryRule($callsiteResolver, $config->duplicateThreshold),
            new QueryInLoopRule($callsiteResolver, $config->queryInLoopThreshold),
            new NoLimitRule($callsiteResolver, $config->largeTables),
            new SelectStarRule($callsiteResolver, $config->selectStar),
            new QueryCountRule($config->maxQueries, $callsiteResolver),
            ...($tier2?->rules() ?? []),
        ]);

        // the baseline is read by every worker and written by none of them (see below),
        // so only the report path is per-worker
        $jsonReportConfigured = $worker->inPath($config->jsonReportPath);

        $baselinePath = self::absolutePath($config->baselinePath, $basePath);
        $jsonReportPath = self::absolutePath($jsonReportConfigured, $basePath);

        if ($worker->isWorker() && '' !== $config->jsonReportPath && !str_contains($config->jsonReportPath, Worker::PLACEHOLDER)) {
            // the file will be written, and it will contain this worker's share of the
            // run — which is the part nobody notices
            $report->addNotice(sprintf(
                'this is ParaTest worker %s and every worker writes "%s": the last one to finish overwrites the others, '
                .'so the file ends up holding part of the run. Put %s in the path to give each worker its own.',
                $worker->token,
                $config->jsonReportPath,
                Worker::PLACEHOLDER,
            ));
        }

        foreach (['baseline' => [$config->baselinePath, $baselinePath], 'report-json' => [$jsonReportConfigured, $jsonReportPath]] as $parameter => [$configured, $absolute]) {
            if ('' !== $configured && self::escapesBasePath($configured, $absolute, $basePath)) {
                $report->addNotice(sprintf(
                    '"%s" is configured as "%s", which climbs out of the project (%s) — '
                    .'check for a stray ".." before trusting what gets read from or written there.',
                    $parameter,
                    $configured,
                    $basePath,
                ));
            }
        }

        $baseline = '' === $baselinePath ? Baseline::empty($basePath) : Baseline::fromFile($baselinePath, $basePath);
        $generated = $config->generateBaseline && '' !== $baselinePath && !$worker->isWorker() ? Baseline::empty($basePath) : null;

        if ($config->generateBaseline && '' !== $baselinePath && $worker->isWorker()) {
            // a baseline is one committed file and cannot be split by `%token%` the way a
            // report can. Every worker writing it leaves whichever share finished last,
            // and the next run then fails on findings nobody added — the run after the
            // mistake is where it would surface, which is the worst place for it
            $report->addNotice(sprintf(
                '%s is set, but this is ParaTest worker %s: every worker would write the same baseline and only one share of the findings would survive. '
                .'Nothing was generated — regenerate the baseline in a sequential run.',
                ExtensionConfiguration::GENERATE_BASELINE_ENV,
                $worker->token,
            ));
        }

        if ($config->generateBaseline && '' === $baselinePath) {
            // the env var alone writes nothing, and a run that quietly did nothing looks
            // exactly like a run that succeeded
            $report->addNotice(sprintf(
                '%s is set, but no "baseline" parameter is configured — there is nowhere to write, so nothing was generated.',
                ExtensionConfiguration::GENERATE_BASELINE_ENV,
            ));
        }

        try {
            $facade->registerSubscribers(
                new PreparationStartedSubscriber($collector, $adapters),
                new PreparedSubscriber($collector, $adapters),
                new FinishedSubscriber($collector, $engine, $report, $baseline, $generated),
                new FailedSubscriber($report),
                new ErroredSubscriber($report),
                new ExecutionFinishedSubscriber(
                    $report,
                    new Reporters([
                        // the JSON report goes first so that a failure to write it can still
                        // reach the console summary — see `Reporters`
                        ...('' === $jsonReportPath ? [] : [new JsonReporter($jsonReportPath, $basePath, $config->failOn, $worker->token)]),
                        ...(false === $stream ? [] : [new ConsoleReporter($stream, $basePath, $config->failOn)]),
                    ]),
                    $collector,
                    $adapters,
                    $config->mode,
                    $generated,
                    $baselinePath,
                    $tier2,
                    $config->failOn,
                    $baseline,
                ),
            );
        } catch (EventFacadeIsSealedException) {
            // Nothing to subscribe to in this process, and saying so would be the wrong
            // kind of loud.
            //
            // ParaTest's parent process bootstraps the configured extensions from
            // `SuiteLoader`, and under Pest that happens after `WrapperRunner::run()` has
            // already sealed PHPUnit's event system. Left to escape, the exception becomes
            // "Bootstrapping of extension ... failed", which PHPUnit records as a
            // test-runner warning — and Pest fails a run on those by default. The result
            // was a green suite, every worker's report written correctly, and exit code 1
            // with nothing in the output to explain it.
            //
            // Standing down here loses nothing: the parent process runs no tests. Each
            // worker is a process of its own that bootstraps this extension again, on an
            // unsealed facade, and it is those processes that do the collecting — which is
            // why `%token%` in `report-json` exists at all.
            if (false !== $stream) {
                fclose($stream);
            }

            return;
        }

        QueryGuard::activate($collector);

        // adapters are installed here and reinstalled before every test: Doctrine hooks
        // in via the application's configuration long before us, Eloquent anew on every
        // test along with the application. Below the registration on purpose — a process
        // that could not subscribe must not be left with a live collector nothing reads.
        $adapters->install($collector);
    }

    /**
     * Whether something other than PHPUnit's own printer is printing this run.
     *
     * `--no-output` normally means "the user asked for silence", and taking it at that
     * word is borrowed from `ergebnis/phpunit-slow-test-detector`. Pest means something
     * else by the same flag: `bin/pest` sets `COLLISION_PRINTER` in its very first line,
     * and `Pest\Plugins\Printer::handleArguments()` then appends `--no-output`
     * unconditionally — because printing is Collision's job there, not PHPUnit's. Under
     * `--parallel` the parent process strips the flag again, but every ParaTest worker
     * is handed it back.
     *
     * Read as silence, that turned every Pest project into the failure this package
     * exists to rule out: a green run, no `report-json`, not a line in the console, and
     * nothing to tell it apart from "no findings". This package prints to a stream it
     * opens itself and never touches PHPUnit's printer, so a replaced printer is no
     * reason for it to go quiet.
     *
     * Both spellings are asked because they answer in different processes: the
     * superglobal is set by the Pest binary and does not survive into a worker, while
     * the class is autoloadable wherever Pest is installed at all.
     */
    private static function printerReplaced(): bool
    {
        return isset($_SERVER['COLLISION_PRINTER']) || class_exists(\Pest\Kernel::class);
    }

    /**
     * A relative path — the baseline, the JSON report — is resolved against the working
     * directory.
     *
     * "Absolute" is tested for both spellings on purpose: the package normalises Windows
     * paths elsewhere (`CallsiteResolver`), and a `C:\` baseline must not end up
     * appended to the working directory.
     */
    private static function absolutePath(string $path, string $basePath): string
    {
        if ('' === $path) {
            return '';
        }

        return self::isAbsolute($path) ? $path : ('' === $basePath ? '.' : $basePath).'/'.$path;
    }

    private static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')          // a UNC share
            || 1 === preg_match('/^[A-Za-z]:[\\\\\/]/', $path);
    }

    /**
     * Whether a path configured relative to the project ended up outside it.
     *
     * Only asked of paths that were not already absolute: an explicit absolute path — a
     * shared cache directory, say — is a deliberate choice and none of this tool's
     * business. A relative one that climbs out via ".." is more likely a stray CI
     * substitution than an intended destination, worth a word before it reads from, or
     * writes to, somewhere the project owner did not expect.
     */
    private static function escapesBasePath(string $configured, string $absolute, string $basePath): bool
    {
        if ('' === $basePath || self::isAbsolute($configured)) {
            return false;
        }

        $base = self::normalize($basePath);
        $target = self::normalize($absolute);

        return $target !== $base && !str_starts_with($target, $base.'/');
    }

    /**
     * Collapses "." and ".." segments without touching the filesystem: the baseline or
     * the JSON report may not exist yet on a first run, so `realpath()` cannot be asked.
     */
    private static function normalize(string $path): string
    {
        $parts = [];

        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ('' === $segment || '.' === $segment) {
                continue;
            }

            if ('..' === $segment) {
                array_pop($parts);

                continue;
            }

            $parts[] = $segment;
        }

        return '/'.implode('/', $parts);
    }
}
