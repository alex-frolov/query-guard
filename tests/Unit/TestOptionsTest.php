<?php

declare(strict_types=1);

namespace QueryGuard\Test\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use QueryGuard\Test\Unit\Fixture\AnnotatedSubject;
use QueryGuard\TestOptions;

#[CoversClass(TestOptions::class)]
final class TestOptionsTest extends TestCase
{
    public function testClassLevelOptionsApplyToEveryMethod(): void
    {
        $options = TestOptions::fromTest(AnnotatedSubject::class, 'inheritsClassOptions');

        self::assertSame(10, $options->allowQueries);
        self::assertTrue($options->isIgnored('duplicate-query'));
    }

    public function testMethodOverridesClassThreshold(): void
    {
        self::assertSame(50, TestOptions::fromTest(AnnotatedSubject::class, 'overridesThreshold')->allowQueries);
    }

    public function testIgnoredRulesFromClassAndMethodAreMerged(): void
    {
        $options = TestOptions::fromTest(AnnotatedSubject::class, 'ignoresMoreRules');

        self::assertTrue($options->isIgnored('duplicate-query'));
        self::assertTrue($options->isIgnored('n-plus-one'));
        self::assertTrue($options->isIgnored('query-count'));
        self::assertFalse($options->isIgnored('select-star'));
    }

    public function testUnknownClassIsNotAnError(): void
    {
        $options = TestOptions::fromTest('No\Such\Klass', 'whatever');

        self::assertNull($options->allowQueries);
        self::assertSame([], $options->ignoredRules);
    }

    public function testUnknownMethodFallsBackToClassOptions(): void
    {
        self::assertSame(10, TestOptions::fromTest(AnnotatedSubject::class, 'noSuchMethod')->allowQueries);
    }
}
