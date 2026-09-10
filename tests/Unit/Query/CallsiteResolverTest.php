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
        CallsiteResolver::configureSkipFunctions([]);
        CallsiteResolver::configureChainDepth(CallsiteResolver::DEFAULT_CHAIN_DEPTH);
    }

    /**
     * The callsite says where the query left from; the chain says who asked for it —
     * which, when the callsite is a shared getter, is the half that identifies the fault.
     */
    public function testTheChainCarriesTheApplicationFramesAboveTheCallsite(): void
    {
        $callsite = (new CallsiteResolver(['#/vendor/#']))->resolve($this->stack());

        self::assertNotNull($callsite);
        self::assertSame('/app/src/Entity/Document.php', $callsite->file);
        self::assertSame([
            '/app/src/Api/Asset.php:31 Api\\Asset::getVersion',
            '/app/src/Event/AssetAdded.php:12 Event\\AssetAdded::getVersionMeta',
            '/app/tests/AssetTest.php:7 AssetTest::testSomething',
        ], $callsite->chain);
    }

    /**
     * The default is four rather than three because a repository wrapper is routinely
     * three frames thick, and at three the whole chain fitted inside one and named nobody.
     * Measured on a Laravel suite built on `prettus/l5-repository`: the frames below are
     * that wrapper, and the project's own repository is the fourth.
     */
    public function testTheDefaultDepthClearsAWrapperThreeFramesThick(): void
    {
        $callsite = CallsiteResolver::default()->resolve([
            ['file' => '/project/vendor/prettus/l5-repository/src/Eloquent/BaseRepository.php', 'line' => 559, 'class' => 'Prettus\\Repository\\Eloquent\\BaseRepository', 'function' => 'findWhere'],
            ['file' => '/project/vendor/prettus/l5-repository/src/Traits/CacheableRepository.php', 'line' => 321, 'class' => 'Prettus\\Repository\\Eloquent\\BaseRepository', 'function' => 'findWhere'],
            ['file' => '/project/vendor/prettus/l5-repository/src/Traits/CacheableRepository.php', 'line' => 320, 'class' => 'Illuminate\\Cache\\CacheManager', 'function' => '__call'],
            ['file' => '/project/packages/Core/src/Eloquent/Repository.php', 'line' => 144, 'class' => 'Core\\Eloquent\\Repository', 'function' => 'findWhere'],
            ['file' => '/project/packages/Core/src/Core.php', 'line' => 129, 'class' => 'Core\\Core', 'function' => 'getConfigData'],
        ]);

        self::assertNotNull($callsite);
        self::assertContains(
            '/project/packages/Core/src/Eloquent/Repository.php:144 Core\\Eloquent\\Repository::findWhere',
            $callsite->chain,
        );
    }

    /**
     * Pest's frames sit between a test body and the runner, not above the callsite. A test
     * that queries directly has nobody above it, and the honest chain there is empty —
     * nine frames of `TestCaseMethodFactory` → `Kernel::handle` name no caller and cannot.
     * `bin/pest` is in the same package but not under `vendor/bin/`, so it needs the
     * package-wide pattern rather than the runner-binary one.
     */
    public function testPestsOwnFramesAreNotMistakenForCallers(): void
    {
        $callsite = CallsiteResolver::default()->resolve([
            ['file' => '/project/packages/Shop/tests/Feature/CartTest.php', 'line' => 64, 'function' => 'get'],
            ['file' => '/project/vendor/pestphp/pest/src/Factories/TestCaseMethodFactory.php', 'line' => 171, 'class' => 'P\\CartTest', 'function' => '{closure}'],
            ['file' => '/project/vendor/pestphp/pest/src/Concerns/Testable.php', 'line' => 419, 'function' => 'call_user_func_array'],
            ['file' => '/project/vendor/pestphp/pest/src/Kernel.php', 'line' => 103, 'class' => 'PHPUnit\\TextUI\\Application', 'function' => 'run'],
            ['file' => '/project/vendor/pestphp/pest/bin/pest', 'line' => 184, 'class' => 'Pest\\Kernel', 'function' => 'handle'],
        ]);

        self::assertNotNull($callsite);
        self::assertSame('/project/packages/Shop/tests/Feature/CartTest.php', $callsite->file);
        self::assertSame([], $callsite->chain);
    }

    public function testTheChainStopsAtTheConfiguredDepth(): void
    {
        $callsite = (new CallsiteResolver(['#/vendor/#'], 1))->resolve($this->stack());

        self::assertNotNull($callsite);
        self::assertSame(['/app/src/Api/Asset.php:31 Api\\Asset::getVersion'], $callsite->chain);
    }

    /**
     * Zero is off, and off means the frames above are never even walked.
     */
    public function testDepthZeroKeepsNoChain(): void
    {
        $callsite = (new CallsiteResolver(['#/vendor/#'], 0))->resolve($this->stack());

        self::assertNotNull($callsite);
        self::assertSame([], $callsite->chain);
    }

    /**
     * Every query of an N+1 leaves the same path behind it. Holding one array for all of
     * them is the difference between three strings per query and three per call path.
     *
     * Asserted through the private cache because PHP arrays have no identity to compare:
     * two chains that are equal are indistinguishable from the outside, and it is exactly
     * their not being two copies that is under test.
     */
    public function testIdenticalChainsAreKeptOnce(): void
    {
        $chains = new \ReflectionProperty(CallsiteResolver::class, 'chains');
        $chains->setValue(null, []);

        $resolver = new CallsiteResolver(['#/vendor/#']);

        $first = $resolver->resolve($this->stack());
        $second = $resolver->resolve($this->stack());

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertSame($first->chain, $second->chain);

        $cached = $chains->getValue();

        self::assertIsArray($cached);
        self::assertCount(1, $cached);
    }

    /**
     * `default()` carries whatever the extension configured, because the resolvers that
     * matter are built where nothing can be injected.
     */
    public function testTheConfiguredDepthReachesTheDefaultResolver(): void
    {
        CallsiteResolver::configureChainDepth(0);

        $callsite = CallsiteResolver::default()->resolve([
            ['file' => '/project/src/A.php', 'line' => 1, 'function' => 'a'],
            ['file' => '/project/src/B.php', 'line' => 2, 'function' => 'b'],
        ]);

        self::assertNotNull($callsite);
        self::assertSame([], $callsite->chain);
    }

    /**
     * The shape this exists for: the lazy load is touched in a getter every caller
     * shares, and the caller is the part worth reading.
     *
     * @return list<array<string, mixed>>
     */
    private function stack(): array
    {
        return [
            ['file' => '/app/vendor/doctrine/orm/src/PersistentCollection.php', 'line' => 200, 'function' => 'initialize'],
            ['file' => '/app/src/Entity/Document.php', 'line' => 78, 'class' => 'Entity\\Document', 'function' => 'getVersion'],
            ['file' => '/app/src/Api/Asset.php', 'line' => 31, 'class' => 'Api\\Asset', 'function' => 'getVersion'],
            ['file' => '/app/src/Event/AssetAdded.php', 'line' => 12, 'class' => 'Event\\AssetAdded', 'function' => 'getVersionMeta'],
            ['file' => '/app/tests/AssetTest.php', 'line' => 7, 'class' => 'AssetTest', 'function' => 'testSomething'],
        ];
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
     * Laravel's middleware pipeline is the one transit frame that cannot be recognised by
     * its path: the file is the application's own middleware and the line is its
     * `return $next($request);`. Every HTTP request passes through every one of them, so
     * as a caller the frame names nobody — and there were four of them between the
     * callsite and the controller on the run that found this.
     */
    public function testTheMiddlewarePipelineIsNotOfferedAsACaller(): void
    {
        $callsite = CallsiteResolver::default()->resolve([
            ['file' => '/project/packages/Admin/src/Http/Resources/ActivityResource.php', 'line' => 30, 'class' => 'Illuminate\\Database\\Eloquent\\Model', 'function' => '__get'],
            ['file' => '/project/packages/Admin/src/Http/Middleware/Bouncer.php', 'line' => 43, 'class' => 'Illuminate\\Pipeline\\Pipeline', 'function' => 'Illuminate\\Pipeline\\{closure}'],
            ['file' => '/project/packages/Admin/src/Http/Middleware/Locale.php', 'line' => 38, 'class' => 'Illuminate\\Pipeline\\Pipeline', 'function' => 'Illuminate\\Pipeline\\{closure}'],
            ['file' => '/project/packages/Admin/src/Http/Controllers/Lead/ActivityController.php', 'line' => 39, 'class' => 'Admin\\Http\\Controllers\\Lead\\ActivityController', 'function' => 'index'],
        ]);

        self::assertNotNull($callsite);
        self::assertSame('/project/packages/Admin/src/Http/Resources/ActivityResource.php', $callsite->file);
        self::assertSame([
            '/project/packages/Admin/src/Http/Controllers/Lead/ActivityController.php:39 Admin\\Http\\Controllers\\Lead\\ActivityController::index',
        ], $callsite->chain);
    }

    /**
     * PHP 8.4 renamed closures to carry their enclosing function, so the same frame reads
     * one way on a modern runtime and another on an older one. The default matches the
     * class, which did not move.
     */
    public function testThePipelineIsRecognisedWhateverPhpCallsItsClosures(): void
    {
        $callsite = CallsiteResolver::default()->resolve([
            ['file' => '/project/src/Entity/Document.php', 'line' => 12, 'class' => 'Entity\\Document', 'function' => 'getVersions'],
            ['file' => '/project/app/Http/Middleware/Locale.php', 'line' => 38, 'class' => 'Illuminate\\Pipeline\\Pipeline', 'function' => 'Illuminate\\Pipeline\\Pipeline::prepareDestination(){closure}'],
            ['file' => '/project/app/Http/Controllers/DocumentController.php', 'line' => 20, 'class' => 'App\\Http\\Controllers\\DocumentController', 'function' => 'show'],
        ]);

        self::assertNotNull($callsite);
        self::assertSame([
            '/project/app/Http/Controllers/DocumentController.php:20 App\\Http\\Controllers\\DocumentController::show',
        ], $callsite->chain);
    }

    /**
     * The middleware's own body is not plumbing. Only the frame that called the pipeline
     * closure is dropped; a query the middleware itself issues keeps its callsite, in the
     * same file, a few lines away from the one that was skipped.
     */
    public function testAQueryTheMiddlewareItselfMakesKeepsItsCallsite(): void
    {
        $callsite = CallsiteResolver::default()->resolve([
            ['file' => '/project/app/Http/Middleware/Locale.php', 'line' => 24, 'class' => 'App\\Repository\\LocaleRepository', 'function' => 'findDefault'],
            ['file' => '/project/app/Http/Middleware/Locale.php', 'line' => 38, 'class' => 'Illuminate\\Pipeline\\Pipeline', 'function' => 'Illuminate\\Pipeline\\{closure}'],
        ]);

        self::assertNotNull($callsite);
        self::assertSame('/project/app/Http/Middleware/Locale.php', $callsite->file);
        self::assertSame(24, $callsite->line);
        self::assertSame([], $callsite->chain);
    }

    /**
     * Same contract as `skip-paths`: a fragment matched anywhere in `Class::method`,
     * quoted rather than read as a pattern, added to the built-in list rather than
     * replacing it.
     */
    public function testConfiguredFunctionsAreSteppedOverByEveryResolverBuiltAfterwards(): void
    {
        $stack = [
            ['file' => '/project/src/Support/Instrumentation.php', 'line' => 15, 'class' => 'App\\Support\\Instrumentation', 'function' => 'measure'],
            ['file' => '/project/src/Repository/OrderRepository.php', 'line' => 42, 'class' => 'App\\Repository\\OrderRepository', 'function' => 'findAll'],
        ];

        self::assertSame('/project/src/Support/Instrumentation.php', self::resolvedFile($stack));

        CallsiteResolver::configureSkipFunctions(['App\\Support\\Instrumentation::']);

        self::assertSame('/project/src/Repository/OrderRepository.php', self::resolvedFile($stack));
    }

    public function testAFunctionFragmentIsQuotedRatherThanReadAsAPattern(): void
    {
        CallsiteResolver::configureSkipFunctions(['App\\Su.port::run']);

        self::assertSame('/project/src/Support/Thing.php', self::resolvedFile([
            ['file' => '/project/src/Support/Thing.php', 'line' => 1, 'class' => 'App\\Support', 'function' => 'run'],
        ]));
    }

    /**
     * A frame with no function at all — an `include`, or a top-level script — is judged
     * by its path alone, as it always was.
     */
    public function testAFrameWithoutAFunctionIsUnaffected(): void
    {
        CallsiteResolver::configureSkipFunctions(['Whatever::']);

        self::assertSame('/project/src/bootstrap.php', self::resolvedFile([
            ['file' => '/project/src/bootstrap.php', 'line' => 3],
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
