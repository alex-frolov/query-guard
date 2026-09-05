<?php

declare(strict_types=1);

namespace QueryGuard;

use PHPUnit\Runner\Extension\Extension as PHPUnitExtension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;
use QueryGuard\Adapter\AdapterSet;
use QueryGuard\Baseline\Baseline;
use QueryGuard\Collector\DefaultQueryCollector;
use QueryGuard\Query\CallsiteResolver;
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
        if ($configuration->noOutput()) {
            return;
        }

        $config = ExtensionConfiguration::fromParameters($parameters);

        $collector = new DefaultQueryCollector();
        QueryGuard::activate($collector);

        // adapters are installed here and reinstalled before every test: Doctrine hooks
        // in via the application's configuration long before us, Eloquent anew on every
        // test along with the application
        $adapters = AdapterSet::detect();
        $adapters->install($collector);

        $stream = fopen($configuration->outputToStandardErrorStream() ? 'php://stderr' : 'php://stdout', 'wb');

        if (false === $stream) {
            // there is nowhere to print the summary, so collecting would only cost
            QueryGuard::deactivate();

            return;
        }

        $basePath = getcwd() ?: '';

        // before any adapter builds a resolver of its own: a DBAL middleware is
        // constructed by the application's container, which cannot be handed one
        CallsiteResolver::configureSkipPaths($config->skipPaths);

        $callsiteResolver = CallsiteResolver::default();
        $report = new Report();

        foreach ($config->warnings as $warning) {
            $report->addNotice($warning);
        }

        $tier2 = $config->tier2
            ? new Tier2Factory($adapters, $collector, $callsiteResolver, $config->minRows)
            : null;

        $engine = new RuleEngine([
            new NPlusOneRule($callsiteResolver, $config->nPlusOneThreshold),
            new DuplicateQueryRule($callsiteResolver, $config->duplicateThreshold),
            new QueryInLoopRule($callsiteResolver, $config->queryInLoopThreshold),
            new NoLimitRule($callsiteResolver, $config->largeTables),
            new SelectStarRule($callsiteResolver, $config->selectStar),
            new QueryCountRule($config->maxQueries, $callsiteResolver),
            ...($tier2?->rules() ?? []),
        ]);

        $baselinePath = self::absolutePath($config->baselinePath, $basePath);
        $jsonReportPath = self::absolutePath($config->jsonReportPath, $basePath);

        foreach (['baseline' => [$config->baselinePath, $baselinePath], 'report-json' => [$config->jsonReportPath, $jsonReportPath]] as $parameter => [$configured, $absolute]) {
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
        $generated = $config->generateBaseline && '' !== $baselinePath ? Baseline::empty($basePath) : null;

        if ($config->generateBaseline && '' === $baselinePath) {
            // the env var alone writes nothing, and a run that quietly did nothing looks
            // exactly like a run that succeeded
            $report->addNotice(sprintf(
                '%s is set, but no "baseline" parameter is configured — there is nowhere to write, so nothing was generated.',
                ExtensionConfiguration::GENERATE_BASELINE_ENV,
            ));
        }

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
                    ...('' === $jsonReportPath ? [] : [new JsonReporter($jsonReportPath, $basePath, $config->failOn)]),
                    new ConsoleReporter($stream, $basePath, $config->failOn),
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
