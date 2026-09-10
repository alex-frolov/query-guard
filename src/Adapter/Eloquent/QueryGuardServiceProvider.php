<?php

declare(strict_types=1);

namespace QueryGuard\Adapter\Eloquent;

use Illuminate\Support\ServiceProvider;

/**
 * Installs the query listener on every application boot.
 *
 * Laravel finds this provider on its own (package discovery), so a project needs no
 * wiring at all. This is also the only moment from which `setUp()` is visible: the
 * test application is created inside `setUp()`, after `Test\PreparationStarted`.
 *
 * It subscribes to the event dispatcher rather than to `DB`: connections put
 * `QueryExecuted` there, so a single subscription covers every connection — including
 * ones created later. `DatabaseManager` has no `listen()` method at all; `DB::listen()`
 * works through `__call` and only ever reaches the default connection.
 *
 * Outside a PHPUnit run the listener hits the null collector and does nothing —
 * it does not even start capturing a stack trace.
 */
final class QueryGuardServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (!$this->app->bound('events')) {
            return;
        }

        $events = $this->app->make('events');

        if (\is_object($events)) {
            EloquentAdapter::attach($events);
        }
    }
}
