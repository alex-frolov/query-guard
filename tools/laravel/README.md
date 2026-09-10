# Laravel stand

A throwaway Laravel application — `orchestra/testbench`, three tests, an `authors`/`books`
schema — that exists for one reason: the package's own suite cannot build a Laravel
application lifecycle, and that lifecycle is where the Eloquent adapter broke.

`Illuminate\Foundation\Testing\TestCase` calls `$this->app->flush()` in teardown and
leaves `Facade::$app` pointing at the emptied application; the facades are rebound by the
`RegisterFacades` bootstrapper on the *next* `setUp()`. So between two tests `DB::getFacadeRoot()`
hands back the previous test's `DatabaseManager` with a container that has nothing in it.
The adapter reinstalls itself on `Test\PreparationStarted`, which is exactly that moment,
and `DatabaseManager` has no `listen()` of its own — the call went through `__call`,
resolved the default connection out of the dead container and threw
`Target class [config] does not exist`. Out of a PHPUnit subscriber that is one
`Exception in third-party event subscriber` warning per test.

It had been happening on every Laravel project the package was ever run against. Pest
prints such a warning as one `WARN` line in the stream with no tally and no change to the
closing line, so nobody noticed; plain PHPUnit prints the stack trace and turns `OK` into
`OK, but there were issues!`, which is where it finally showed. A hand-built
`Illuminate\Database\Capsule\Manager` — which is what the package's integration tests use —
has no facades to go stale and cannot reproduce any of it.

**Three tests, and the count is load-bearing.** The first test has nothing stale behind
it; the defect appears from the second one on. With the adapter broken this stand reports
two warnings, not three, and a single-test stand would pass.

The provider is registered by hand in `TestCase::getPackageProviders()` because testbench
does not run package discovery for the package under test. That is not a shortcut: the
provider is the only subscription path that sees queries issued in `setUp()`, so without
it the fixture bucket would come back empty and the stand would be exercising the fallback
alone. Discovery itself is covered by running the package against real applications.

    composer update
    vendor/bin/phpunit

The run writes `var/query-guard.json`. The `laravel` job in CI fails if that file reports
fewer than three tests, if it carries no findings, if the fixture bucket is empty, or if
the word `third-party` appears anywhere in the output.
