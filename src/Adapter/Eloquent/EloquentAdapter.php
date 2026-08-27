<?php

declare(strict_types=1);

namespace QueryGuard\Adapter\Eloquent;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use QueryGuard\Adapter\Explainer;
use QueryGuard\Adapter\OrmAdapter;
use QueryGuard\Collector\QueryCollector;
use QueryGuard\Query\QueryEvent;
use QueryGuard\QueryGuard;

/**
 * Eloquent: interception through `listen()`.
 *
 * **A deliberate stub, not an unfinished one.** There is no enrichment, yet the adapter
 * is already useful: the core detects N+1 from the trace alone, without knowing the ORM.
 * It was written alongside the Doctrine adapter on purpose — designing seams around an
 * implementation that does not exist is the standard way to get them wrong.
 *
 * **When to hook in is the real difference from Doctrine, and it took a run against a
 * live Laravel app to get right.** The application is created inside the test class's
 * `setUp()`, i.e. AFTER `Test\PreparationStarted`: at start-up the `DB` facade points at
 * nothing, and between tests it points at an application that has already been thrown
 * away. Hence three subscription paths, from most to least reliable:
 *
 * 1. `QueryGuardServiceProvider` — Laravel loads it on every application boot via package
 *    discovery. The only way to also see queries made in `setUp()`;
 * 2. `Test\Prepared` — the application exists, the test body has not started yet;
 * 3. `Test\PreparationStarted` — for unusual suites where the application outlives a test.
 *
 * Repeated attempts are safe: the same application is subscribed only once.
 */
final class EloquentAdapter implements OrmAdapter
{
    /** @var \WeakMap<object, true>|null */
    private static ?\WeakMap $subscribed = null;

    private static bool $attached = false;

    /**
     * @param object|null $resolver a connection manager or a connection itself;
     *                              defaults to the root of the `DB` facade
     */
    public function __construct(private readonly ?object $resolver = null)
    {
    }

    public static function supports(): bool
    {
        return class_exists(QueryExecuted::class);
    }

    /**
     * Subscribes the listener to an event dispatcher, a connection manager or a connection.
     *
     * The dispatcher is preferred: `Illuminate\Database\Connection::listen()` registers
     * the listener there, so subscribing to the dispatcher covers every connection at
     * once, including ones created later. A connection manager (`DatabaseManager`) will
     * not do: it has no `listen()` method at all — `DB::listen()` goes through `__call`
     * and only ever reaches the default connection (verified on Laravel 13).
     *
     * @return bool whether the subscription succeeded (or was already in place)
     */
    public static function attach(object $target, ?QueryCollector $collector = null): bool
    {
        self::$subscribed ??= new \WeakMap();

        if (self::$subscribed->offsetExists($target)) {
            return self::$attached = true;
        }

        $listener = static function (QueryExecuted $query) use ($collector): void {
            $sink = $collector ?? QueryGuard::collector();

            if (!$sink->isRecording()) {
                return;
            }

            $sink->record(new QueryEvent(
                sql: $query->sql,
                params: array_values($query->bindings),
                durationMs: $query->time,
                connection: $query->connectionName,
                stack: debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS, 200),
            ));
        };

        if ($target instanceof Dispatcher) {
            $target->listen(QueryExecuted::class, $listener);
        } elseif (\is_callable([$target, 'listen'])) {
            // a connection or a connection manager — covers one connection only
            $target->listen($listener);
        } else {
            return false;
        }

        self::$subscribed->offsetSet($target, true);

        return self::$attached = true;
    }

    public static function reset(): void
    {
        self::$subscribed = null;
        self::$attached = false;
    }

    public function name(): string
    {
        return 'eloquent';
    }

    public function install(QueryCollector $collector): void
    {
        $resolver = $this->resolve();

        if (null !== $resolver) {
            self::attach($resolver, $collector);
        }
    }

    public function isInstalled(): bool
    {
        return self::$attached;
    }

    public function explainer(): ?Explainer
    {
        // tier 2 is not wired for Eloquent yet: `QueryExecuted` gives no access to a
        // connection on which EXPLAIN could run inside the same transaction. That needs
        // its own solution, and there is nothing to quietly substitute.
        return null;
    }

    public function installationHint(): string
    {
        return 'Eloquent: the listener is installed by QueryGuardServiceProvider — '
            .'check that the package is not excluded from package discovery in composer.json (extra.laravel.dont-discover).';
    }

    private function resolve(): ?object
    {
        if (null !== $this->resolver) {
            return $this->resolver;
        }

        if (!class_exists(DB::class)) {
            return null;
        }

        try {
            $root = DB::getFacadeRoot();
        } catch (\Throwable) {
            return null;
        }

        return \is_object($root) ? $root : null;
    }
}
