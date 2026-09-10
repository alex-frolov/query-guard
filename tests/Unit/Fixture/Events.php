<?php

declare(strict_types=1);

namespace QueryGuard\Test\Unit\Fixture;

use PHPUnit\Event\Code\Phpt;
use PHPUnit\Event\Code\Test;
use PHPUnit\Event\Code\TestDox;
use PHPUnit\Event\Code\TestMethod;
use PHPUnit\Event\Code\ThrowableBuilder;
use PHPUnit\Event\Telemetry\Info;
use PHPUnit\Event\Test\Errored;
use PHPUnit\Event\Test\Failed;
use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\Prepared;
use PHPUnit\Event\TestData\TestDataCollection;
use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Metadata\MetadataCollection;

/**
 * PHPUnit's own event objects, built by hand.
 *
 * The subscribers accept these and nothing else, so a unit test has to produce them.
 * Two details make that survive PHPUnit 10.5 through 13:
 *
 * - the event constructors themselves have not moved, and `Code\TestMethod` only ever
 *   gained parameters that have defaults;
 * - `Telemetry\Info` is the one that did move — five constructor parameters in 10.5,
 *   eleven in 13. Nothing in the package reads the telemetry, so the object is made
 *   without calling the constructor at all rather than filled with a shape that would
 *   have to be maintained per version.
 */
final class Events
{
    public static function preparationStarted(?Test $test = null): PreparationStarted
    {
        return new PreparationStarted(self::telemetry(), $test ?? self::testMethod());
    }

    public static function prepared(?Test $test = null): Prepared
    {
        return new Prepared(self::telemetry(), $test ?? self::testMethod());
    }

    public static function finished(): Finished
    {
        return new Finished(self::telemetry(), self::testMethod(), 1);
    }

    public static function failed(): Failed
    {
        return new Failed(self::telemetry(), self::testMethod(), ThrowableBuilder::from(new \RuntimeException('failed')), null);
    }

    public static function errored(): Errored
    {
        return new Errored(self::telemetry(), self::testMethod(), ThrowableBuilder::from(new \RuntimeException('errored')));
    }

    public static function executionFinished(): ExecutionFinished
    {
        return new ExecutionFinished(self::telemetry());
    }

    /**
     * A test method of `AnnotatedSubject`, so that the options a subscriber reads off the
     * attributes are real ones.
     *
     * @param non-empty-string $methodName
     */
    public static function testMethod(string $methodName = 'inheritsClassOptions'): TestMethod
    {
        return new TestMethod(
            AnnotatedSubject::class,
            $methodName,
            '/project/tests/AnnotatedSubjectTest.php',
            10,
            new TestDox('Annotated subject', 'Inherits class options', 'Inherits class options'),
            MetadataCollection::fromArray([]),
            TestDataCollection::fromArray([]),
        );
    }

    /**
     * A test that is not a `TestMethod` — the branch where there is no class and no
     * method to read attributes from.
     */
    public static function phpt(): Phpt
    {
        return new Phpt('/project/tests/example.phpt');
    }

    private static function telemetry(): Info
    {
        return (new \ReflectionClass(Info::class))->newInstanceWithoutConstructor();
    }
}
