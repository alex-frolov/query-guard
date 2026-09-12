<?php

declare(strict_types=1);

namespace QueryGuard\Test\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PHPUnit\Runner\Extension\ParameterCollection;
use QueryGuard\ExtensionConfiguration;
use QueryGuard\Finding\Severity;
use QueryGuard\Mode;

/**
 * A misunderstood parameter is the quietest way for the tool to switch itself off:
 * `mode="strickt"` used to leave the suite in `report`, where nothing fails, and say
 * nothing about it.
 */
#[CoversClass(ExtensionConfiguration::class)]
final class ExtensionConfigurationTest extends TestCase
{
    public function testDefaultsAreSilentRules(): void
    {
        $config = self::configure([]);

        self::assertSame(Mode::Report, $config->mode);
        self::assertNull($config->maxQueries);
        self::assertNull($config->maxTraceQueries);
        self::assertFalse($config->selectStar);
        self::assertFalse($config->tier2);
        self::assertSame([], $config->largeTables);
        self::assertSame([], $config->fixturePaths);
        self::assertSame([], $config->warnings);
    }

    public function testValidValuesAreRead(): void
    {
        $config = self::configure([
            'mode' => ' STRICT ',
            'max-queries' => '50',
            'max-trace-queries' => '20000',
            'n-plus-one-threshold' => '4',
            'duplicate-query-threshold' => '7',
            'select-star' => 'yes',
            'tier2' => 'On',
            'large-tables' => ' users , orders ,, ',
            'min-rows' => '25000',
            'fixture-paths' => ' database/factories , database/migrations ,, ',
        ]);

        self::assertSame(Mode::Strict, $config->mode);
        self::assertSame(50, $config->maxQueries);
        self::assertSame(20000, $config->maxTraceQueries);
        self::assertSame(4, $config->nPlusOneThreshold);
        self::assertSame(7, $config->duplicateThreshold);
        self::assertTrue($config->selectStar);
        self::assertTrue($config->tier2);
        self::assertSame(['users', 'orders'], $config->largeTables);
        self::assertSame(25000, $config->minRows);
        self::assertSame(['database/factories', 'database/migrations'], $config->fixturePaths);
        self::assertSame([], $config->warnings);
    }

    public function testAnUnknownModeWarnsInsteadOfFallingBackInSilence(): void
    {
        $config = self::configure(['mode' => 'strickt']);

        self::assertSame(Mode::Report, $config->mode);
        self::assertCount(1, $config->warnings);
        self::assertStringContainsString('strickt', $config->warnings[0]);
        self::assertStringContainsString('nothing fails', $config->warnings[0]);
    }

    public function testANonNumericThresholdWarns(): void
    {
        $config = self::configure(['n-plus-one-threshold' => 'three']);

        self::assertSame(3, $config->nPlusOneThreshold);
        self::assertCount(1, $config->warnings);
        self::assertStringContainsString('n-plus-one-threshold="three"', $config->warnings[0]);
    }

    /**
     * A threshold of 1 would make every single query an N+1. It is rejected — and said so.
     */
    public function testAThresholdBelowTheMinimumWarns(): void
    {
        $config = self::configure(['n-plus-one-threshold' => '1']);

        self::assertSame(3, $config->nPlusOneThreshold);
        self::assertCount(1, $config->warnings);
    }

    /**
     * `max-queries` has no default: the warning has to say the rule stays silent rather
     * than name a number that does not exist.
     */
    public function testAnUnreadableBudgetSaysTheRuleStaysSilent(): void
    {
        $config = self::configure(['max-queries' => 'lots']);

        self::assertNull($config->maxQueries);
        self::assertStringContainsString('stays silent', $config->warnings[0]);
    }

    /**
     * Off by default for the same reason as `max-queries`: a value that could not be
     * read must not silently switch the safeguard on with some made-up number.
     */
    public function testAnUnreadableTraceLimitSaysItStaysOff(): void
    {
        $config = self::configure(['max-trace-queries' => 'lots']);

        self::assertNull($config->maxTraceQueries);
        self::assertStringContainsString('max-trace-queries="lots"', $config->warnings[0]);
    }

    public function testAnUnreadableFlagWarnsAndIsReadAsOff(): void
    {
        $config = self::configure(['select-star' => 'yes please']);

        self::assertFalse($config->selectStar);
        self::assertCount(1, $config->warnings);
        self::assertStringContainsString('yes please', $config->warnings[0]);
    }

    /**
     * An explicit "off" is not a mistake and must not produce noise.
     */
    public function testAnExplicitOffIsNotAWarning(): void
    {
        self::assertSame([], self::configure(['select-star' => 'false', 'tier2' => '0'])->warnings);
    }

    /**
     * The default leaves `info` out. The only `info` rule is `select-star`, and
     * `select *` is Eloquent's default mode — failing on it would turn a whole Laravel
     * suite red the moment someone enables the rule.
     */
    public function testFailOnDefaultsToWarning(): void
    {
        self::assertSame(Severity::Warning, self::configure([])->failOn);
    }

    public function testFailOnIsRead(): void
    {
        self::assertSame(Severity::Error, self::configure(['fail-on' => 'error'])->failOn);
        self::assertSame(Severity::Info, self::configure(['fail-on' => 'INFO'])->failOn);
        self::assertSame(Severity::Warning, self::configure(['fail-on' => ' warning '])->failOn);
    }

    public function testAnUnknownFailOnIsWarnedAboutRatherThanAppliedSilently(): void
    {
        $config = self::configure(['fail-on' => 'critical']);

        self::assertSame(Severity::Warning, $config->failOn);
        self::assertCount(1, $config->warnings);
        self::assertStringContainsString('fail-on="critical" is not a severity', $config->warnings[0]);
    }

    /**
     * Dropping the old name would be a silent break: PHPUnit cannot list the parameters
     * that were written, so the rule would quietly fall back to its default.
     */
    public function testTheOldDuplicateThresholdNameStillWorksAndAsksForTheRename(): void
    {
        $config = self::configure(['duplicate-threshold' => '8']);

        self::assertSame(8, $config->duplicateThreshold);
        self::assertCount(1, $config->warnings);
        self::assertStringContainsString('duplicate-threshold is the old name of duplicate-query-threshold', $config->warnings[0]);
        self::assertStringContainsString('rename it', $config->warnings[0]);
    }

    public function testWithBothNamesTheNewOneWinsAndTheOldOneIsCalledOut(): void
    {
        $config = self::configure(['duplicate-threshold' => '8', 'duplicate-query-threshold' => '9']);

        self::assertSame(9, $config->duplicateThreshold);
        self::assertCount(1, $config->warnings);
        self::assertStringContainsString('duplicate-threshold was ignored', $config->warnings[0]);
    }

    /**
     * An unreadable value under the old name is still reported under the name written.
     */
    public function testAnUnreadableValueUnderTheOldNameNamesTheOldName(): void
    {
        $config = self::configure(['duplicate-threshold' => 'lots']);

        self::assertSame(5, $config->duplicateThreshold);
        self::assertCount(2, $config->warnings);
        self::assertStringContainsString('duplicate-threshold="lots"', $config->warnings[1]);
    }

    public function testWarningsAccumulate(): void
    {
        $config = self::configure([
            'mode' => 'quiet',
            'max-queries' => '-5',
            'tier2' => 'maybe',
        ]);

        self::assertCount(3, $config->warnings);
    }

    /**
     * @param array<string, string> $parameters
     */
    private static function configure(array $parameters): ExtensionConfiguration
    {
        return ExtensionConfiguration::fromParameters(ParameterCollection::fromArray($parameters));
    }
}
