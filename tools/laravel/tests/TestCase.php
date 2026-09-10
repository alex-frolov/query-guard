<?php

declare(strict_types=1);

namespace QueryGuard\Stand\Laravel;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as TestbenchTestCase;
use QueryGuard\Adapter\Eloquent\QueryGuardServiceProvider;

/**
 * A real Laravel application, created and destroyed around every test.
 *
 * That lifecycle is the whole point of the stand. `Illuminate\Foundation\Testing\TestCase`
 * calls `$this->app->flush()` in teardown and leaves `Facade::$app` pointing at the
 * emptied application until the next `setUp()` rebinds it — so between two tests the `DB`
 * facade hands back a `DatabaseManager` whose container is gone. Nothing inside the
 * package's own suite reproduces that; a hand-built `Capsule` has no facades to go stale.
 */
abstract class TestCase extends TestbenchTestCase
{
    /**
     * Testbench does not run package discovery for the package under test, so the
     * provider is listed by hand. Discovery itself is exercised by the real applications
     * the package is run against; what this stand is here for is the lifecycle.
     *
     * It matters that the provider is registered at all: it is the only subscription path
     * that sees queries made in `setUp()`, and without it the fixture bucket would be
     * empty and the stand would be silently testing the fallback alone.
     *
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [QueryGuardServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'stand');
        $app['config']->set('database.connections.stand', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('authors', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });

        Schema::create('books', static function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('author_id');
            $table->string('title');
        });

        foreach (['Le Guin', 'Lem', 'Strugatsky'] as $index => $name) {
            Author::query()->create(['id' => $index + 1, 'name' => $name]);
            Book::query()->create(['author_id' => $index + 1, 'title' => $name.' book']);
        }
    }
}
