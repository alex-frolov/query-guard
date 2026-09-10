<?php

declare(strict_types=1);

namespace QueryGuard\Test\Unit\Collector;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use QueryGuard\Collector\DefaultQueryCollector;
use QueryGuard\Collector\FixtureFilter;
use QueryGuard\Query\CallsiteResolver;
use QueryGuard\Query\QueryEvent;
use QueryGuard\TestIdentifier;
use QueryGuard\TestOptions;

/**
 * The second answer to "is this the stand or the application", by call site rather than
 * by test phase — see the class docblock for why the phase alone stopped being enough.
 */
#[CoversClass(FixtureFilter::class)]
final class FixtureFilterTest extends TestCase
{
    public function testAnEmptyListMatchesNothingAndSaysSo(): void
    {
        $filter = new FixtureFilter([], new CallsiteResolver([]));

        self::assertTrue($filter->isEmpty());
        self::assertFalse($filter->isFixture($this->event('/app/database/factories/SongFactory.php')));
    }

    public function testAQueryFromAConfiguredDirectoryIsAFixture(): void
    {
        $filter = new FixtureFilter(['database/factories'], new CallsiteResolver([]));

        self::assertFalse($filter->isEmpty());
        self::assertTrue($filter->isFixture($this->event('/app/database/factories/SongFactory.php')));
        self::assertFalse($filter->isFixture($this->event('/app/app/Repositories/SongRepository.php')));
    }

    /**
     * The same notation as `skip-paths`, because the two sit next to each other in the
     * same XML: a fragment, slashes optional, backslashes normalised.
     */
    public function testFragmentsAreReadTheWaySkipPathsReadsThem(): void
    {
        $filter = new FixtureFilter(['/database/migrations/', 'database\\seeders'], new CallsiteResolver([]));

        self::assertTrue($filter->isFixture($this->event('/app/database/migrations/2015_create_songs.php')));
        self::assertTrue($filter->isFixture($this->event('/app/database/seeders/SongSeeder.php')));
    }

    /**
     * Every frame skipped says nothing about what the query was for, so it stays with the
     * rules rather than being quietly filed away under a configuration written for
     * something else.
     */
    public function testAQueryWithNoResolvableCallsiteIsNotAFixture(): void
    {
        $filter = new FixtureFilter(['database/factories'], new CallsiteResolver(['#.*#']));

        self::assertFalse($filter->isFixture($this->event('/app/database/factories/SongFactory.php')));
    }

    /**
     * The point of the whole thing: a factory called from the body of a test, where the
     * `setUp()` boundary does not reach, still ends up counted as preparation and shown
     * to no rule.
     */
    public function testTheCollectorFilesSuchAQueryWhereSetUpQueriesGo(): void
    {
        $collector = new DefaultQueryCollector(
            null,
            new FixtureFilter(['database/factories'], new CallsiteResolver([])),
        );

        $collector->beginFixtures();
        $trace = $collector->beginTrace(new TestIdentifier('id', 'SongTest::testIndex'), TestOptions::none());

        $collector->record($this->event('/app/database/factories/SongFactory.php'));
        $collector->record($this->event('/app/app/Repositories/SongRepository.php'));

        self::assertCount(1, $trace->events());
        self::assertSame(1, $trace->count());
        self::assertSame(1, $trace->fixtureQueryCount());
        self::assertSame(2, $collector->totalRecorded());
    }

    public function testWithoutTheFilterEverythingStillReachesTheRules(): void
    {
        $collector = new DefaultQueryCollector();

        $collector->beginFixtures();
        $trace = $collector->beginTrace(new TestIdentifier('id', 'SongTest::testIndex'), TestOptions::none());

        $collector->record($this->event('/app/database/factories/SongFactory.php'));

        self::assertCount(1, $trace->events());
        self::assertSame(0, $trace->fixtureQueryCount());
    }

    private function event(string $file): QueryEvent
    {
        return new QueryEvent(
            sql: 'SELECT 1',
            stack: [['file' => $file, 'line' => 19, 'function' => 'create', 'class' => 'Factory', 'type' => '->']],
        );
    }
}
