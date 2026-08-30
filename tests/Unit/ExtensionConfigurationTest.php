<?php

declare(strict_types=1);

namespace QueryGuard\Test\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PHPUnit\Runner\Extension\ParameterCollection;
use QueryGuard\ExtensionConfiguration;
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
        self::assertFalse($config->selectStar);
        self::assertFalse($config->tier2);
        self::assertSame([], $config->largeTables);
        self::assertSame([], $config->warnings);
    }

    public function testValidValuesAreRead(): void
    {
        $config = self::configure([
            'mode' => ' STRICT ',
            'max-queries' => '50',
            'n-plus-one-threshold' => '4',
            'select-star' => 'yes',
            'tier2' => 'On',
            'large-tables' => ' users , orders ,, ',
            'min-rows' => '25000',
        ]);

        self::assertSame(Mode::Strict, $config->mode);
        self::assertSame(50, $config->maxQueries);
        self::assertSame(4, $config->nPlusOneThreshold);
        self::assertTrue($config->selectStar);
        self::assertTrue($config->tier2);
        self::assertSame(['users', 'orders'], $config->largeTables);
        self::assertSame(25000, $config->minRows);
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
