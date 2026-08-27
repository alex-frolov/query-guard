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
use QueryGuard\Report\Report;
use QueryGuard\Rule\DuplicateQueryRule;
use QueryGuard\Rule\NoLimitRule;
use QueryGuard\Rule\NPlusOneRule;
use QueryGuard\Rule\QueryCountRule;
use QueryGuard\Rule\QueryInLoopRule;
use QueryGuard\Rule\RuleEngine;
use QueryGuard\Rule\SelectStarRule;
use QueryGuard\Rule\Tier2Factory;
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
            return;
        }

        $callsiteResolver = CallsiteResolver::default();
        $report = new Report();

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
        ], null === $tier2 ? null : $tier2->rules(...));

        $baselinePath = '' === $config->baselinePath
            ? ''
            : (str_starts_with($config->baselinePath, '/') ? $config->baselinePath : (getcwd() ?: '.').'/'.$config->baselinePath);

        $basePath = getcwd() ?: '';
        $baseline = '' === $baselinePath ? Baseline::empty($basePath) : Baseline::fromFile($baselinePath, $basePath);
        $generated = $config->generateBaseline && '' !== $baselinePath ? Baseline::empty($basePath) : null;

        $facade->registerSubscribers(
            new PreparationStartedSubscriber($collector, $adapters),
            new PreparedSubscriber($collector, $adapters),
            new FinishedSubscriber($collector, $engine, $report, $baseline, $generated),
            new ExecutionFinishedSubscriber(
                $report,
                new ConsoleReporter($stream, getcwd() ?: ''),
                $collector,
                $adapters,
                $config->mode,
                $generated,
                $baselinePath,
                $tier2,
            ),
        );
    }
}
