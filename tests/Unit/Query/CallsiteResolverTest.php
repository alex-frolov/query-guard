<?php

declare(strict_types=1);

namespace QueryGuard\Test\Unit\Query;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use QueryGuard\Query\CallsiteResolver;

#[CoversClass(CallsiteResolver::class)]
final class CallsiteResolverTest extends TestCase
{
    protected function tearDown(): void
    {
        // static, because a DBAL middleware is built by the application's container and
        // cannot be handed a configured resolver — see the class docblock
        CallsiteResolver::configureSkipPaths([]);
    }

    public function testReturnsFirstApplicationFrame(): void
    {
        $resolver = new CallsiteResolver(['#/vendor/#']);

        $callsite = $resolver->resolve([
            ['file' => '/app/vendor/doctrine/dbal/src/Statement.php', 'line' => 10, 'function' => 'execute'],
            ['file' => '/app/src/Repository/OrderRepository.php', 'line' => 42, 'class' => 'OrderRepository', 'function' => 'findAll'],
            ['file' => '/app/tests/OrderTest.php', 'line' => 7, 'function' => 'testSomething'],
        ]);

        self::assertNotNull($callsite);
        self::assertSame('/app/src/Repository/OrderRepository.php', $callsite->file);
        self::assertSame(42, $callsite->line);
        self::assertSame('OrderRepository::findAll', $callsite->function);
    }

    /**
     * Frames are judged by their `file`, which is the caller's file. A frame whose class
     * is `DefaultQueryCollector` carries an application file and must not be dropped.
     */
    public function testFrameIsJudgedByFileNotByClass(): void
    {
        $resolver = CallsiteResolver::default();

        $callsite = $resolver->resolve([
            ['file' => '/project/src/Service/Report.php', 'line' => 5, 'class' => 'QueryGuard\Collector\DefaultQueryCollector', 'function' => 'record'],
        ]);

        self::assertNotNull($callsite);
        self::assertSame('/project/src/Service/Report.php', $callsite->file);
    }

    /**
     * The package's own files are excluded by the path to `src/` as a whole rather than
     * by listing file names — an enumerated list is exactly what a competitor got wrong.
     */
    public function testOwnSourceFilesAreSkippedWhereverThePackageLives(): void
    {
        $ownFile = \dirname(__DIR__, 3).'/src/Collector/DefaultQueryCollector.php';

        $callsite = CallsiteResolver::default()->resolve([
            ['file' => $ownFile, 'line' => 30, 'function' => 'record'],
            ['file' => '/project/src/Controller/OrderController.php', 'line' => 12, 'function' => 'list'],
        ]);

        self::assertNotNull($callsite);
        self::assertSame('/project/src/Controller/OrderController.php', $callsite->file);
    }

    public function testFramesWithoutFileAreSkipped(): void
    {
        $callsite = (new CallsiteResolver([]))->resolve([
            ['function' => 'call_user_func'],
            ['file' => '/app/src/A.php', 'line' => 3],
        ]);

        self::assertNotNull($callsite);
        self::assertSame('/app/src/A.php', $callsite->file);
    }

    public function testReturnsNullWhenEverythingIsSkipped(): void
    {
        self::assertNull((new CallsiteResolver(['#/vendor/#']))->resolve([
            ['file' => '/app/vendor/phpunit/phpunit/src/Framework/TestCase.php', 'line' => 1],
        ]));
    }

    /**
     * The verdict per file is cached — resolution runs on every recorded query. The
     * cache must not become a second source of truth: the same resolver has to keep
     * answering the same way, and a different set of patterns has to answer differently.
     */
    public function testCachingTheVerdictDoesNotChangeIt(): void
    {
        $stack = [
            ['file' => '/app/vendor/doctrine/dbal/src/Connection.php', 'line' => 1, 'function' => 'execute'],
            ['file' => '/app/src/Repository/OrderRepository.php', 'line' => 88, 'function' => 'find'],
        ];

        $resolver = new CallsiteResolver(['#/vendor/doctrine/#']);

        $first = $resolver->resolve($stack);
        $second = $resolver->resolve($stack);

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertSame('/app/src/Repository/OrderRepository.php:88', (string) $first);
        self::assertSame((string) $first, (string) $second);

        // a resolver with wider patterns has its own cache and its own answer
        $wider = $resolver->withPatterns(['#/app/src/Repository/#']);

        self::assertNull($wider->resolve($stack));
    }

    /**
     * The `skip-paths` parameter. A project's own framework is not something the package
     * can enumerate, and a callsite pointing inside one names a file nobody will edit.
     *
     * Paths here are `/project/...`, not `/app/...`: `default()` steps over this
     * package's own `src/`, and under the container that is exactly `/app/src`.
     */
    public function testConfiguredPathsAreSteppedOverByEveryResolverBuiltAfterwards(): void
    {
        $stack = [
            ['file' => '/project/vendor/api-platform/core/src/State/Provider.php', 'line' => 10, 'function' => 'provide'],
            ['file' => '/project/src/Repository/OrderRepository.php', 'line' => 42, 'function' => 'findAll'],
        ];

        self::assertSame('/project/vendor/api-platform/core/src/State/Provider.php', self::resolvedFile($stack));

        CallsiteResolver::configureSkipPaths(['vendor/api-platform']);

        self::assertSame('/project/src/Repository/OrderRepository.php', self::resolvedFile($stack));
    }

    /**
     * A fragment, not a regular expression: an XML attribute is no place to discover that
     * `.` matches anything.
     */
    public function testAFragmentIsQuotedRatherThanReadAsAPattern(): void
    {
        CallsiteResolver::configureSkipPaths(['lib/a.c']);

        self::assertSame('/project/lib/abc/Thing.php', self::resolvedFile([
            ['file' => '/project/lib/abc/Thing.php', 'line' => 1, 'function' => 'run'],
        ]));
    }

    public function testSurroundingSlashesAndEmptyFragmentsAreIgnored(): void
    {
        CallsiteResolver::configureSkipPaths(['/vendor/acme/', '', '   ']);

        self::assertSame('/project/lib/Thing.php', self::resolvedFile([
            ['file' => '/project/vendor/acme/lib/Thing.php', 'line' => 1, 'function' => 'run'],
            ['file' => '/project/lib/Thing.php', 'line' => 2, 'function' => 'run'],
        ]));
    }

    /**
     * @param list<array<string, mixed>> $stack
     */
    private static function resolvedFile(array $stack): ?string
    {
        return CallsiteResolver::default()->resolve($stack)?->file;
    }
}
