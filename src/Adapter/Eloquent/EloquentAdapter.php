<?php

declare(strict_types=1);

namespace QueryGuard\Adapter\Eloquent;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use QueryGuard\Adapter\OrmAdapter;
use QueryGuard\Collector\QueryCollector;
use QueryGuard\Query\CallsiteResolver;
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
 *
 * **The dispatcher is looked for before anything else.** `DB::getFacadeRoot()` hands back
 * a `DatabaseManager`, which has no `listen()` of its own: the call goes through
 * `__call` and lands on the default connection, covering that one and nothing else. That
 * is a subscription which succeeds, reports itself installed, and silently misses every
 * secondary connection — the exact shape of failure this package exists to refuse. So the
 * resolver asks the container for `events` first, then the manager for the dispatcher its
 * connections share, and only then falls back to a connection-level `listen()` — which
 * says out loud, in the summary, how much of the project it can see.
 */
final class EloquentAdapter implements OrmAdapter
{
    /** @var \WeakMap<object, true>|null */
    private static ?\WeakMap $subscribed = null;

    private static bool $attached = false;

    /**
     * Set when the only subscription available reached a single connection.
     *
     * Kept apart from `$attached`: the run does collect queries, so nothing in the
     * summary would otherwise suggest that whole connections went unwatched.
     */
    private static bool $singleConnectionOnly = false;

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

        $callsiteResolver = CallsiteResolver::default();

        $listener = static function (QueryExecuted $query) use ($collector, $callsiteResolver): void {
            $sink = $collector ?? QueryGuard::collector();

            if (!$sink->isRecording()) {
                return;
            }

            // resolved here rather than stored: 200 frames per query held until the end
            // of the test cost ~106 MB per 1000 queries — see QueryEvent
            $callsite = $callsiteResolver->resolve(debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS, 200));

            $sink->record(new QueryEvent(
                sql: $query->sql,
                params: array_values($query->bindings),
                durationMs: $query->time,
                connection: $query->connectionName,
                callsite: $callsite,
            ));
        };

        if ($target instanceof Dispatcher) {
            $target->listen(QueryExecuted::class, $listener);
        } elseif (\is_callable([$target, 'listen'])) {
            // a connection, or a manager forwarding through `__call` to the default
            // connection — either way this covers one connection and no other
            $target->listen($listener);
            self::$singleConnectionOnly = true;
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
        self::$singleConnectionOnly = false;
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

    public function explainers(): array
    {
        // tier 2 is not wired for Eloquent yet: `QueryExecuted` gives no access to a
        // connection on which EXPLAIN could run inside the same transaction. That needs
        // its own solution, and there is nothing to quietly substitute.
        return [];
    }

    public function installationHint(): string
    {
        return 'Eloquent: the listener is installed by QueryGuardServiceProvider — '
            .'check that the package is not excluded from package discovery in composer.json (extra.laravel.dont-discover).';
    }

    public function notices(): array
    {
        if (!self::$singleConnectionOnly) {
            return [];
        }

        return [
            "Eloquent: no event dispatcher could be reached, so the listener sits on a single connection.\n"
            .'Queries on every other connection were not seen at all — this is not "all clear". '
            .'QueryGuardServiceProvider subscribes to the dispatcher and covers them all; check that the '
            .'package is not excluded from package discovery in composer.json (extra.laravel.dont-discover).',
        ];
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

        if (!\is_object($root)) {
            return null;
        }

        return self::dispatcherBehind($root) ?? $root;
    }

    /**
     * The dispatcher a connection manager's connections publish to.
     *
     * Two ways in, because a `Manager` outside Laravel has no container: the application
     * container the facade is bound to, and the manager itself — `getEventDispatcher()`
     * reaches the default connection through `__call`, and every connection of that
     * manager shares the object it returns. Subscribing there covers connections opened
     * later, which is the whole reason to prefer it.
     */
    private static function dispatcherBehind(object $root): ?Dispatcher
    {
        try {
            $application = DB::getFacadeApplication();

            if ($application instanceof Container && $application->bound('events')) {
                $events = $application->make('events');

                if ($events instanceof Dispatcher) {
                    return $events;
                }
            }
        } catch (\Throwable) {
            // no container, or nothing bound under `events` — the manager is next
        }

        try {
            if (\is_callable([$root, 'getEventDispatcher'])) {
                $events = $root->getEventDispatcher();

                if ($events instanceof Dispatcher) {
                    return $events;
                }
            }
        } catch (\Throwable) {
            // a manager with no connection configured yet answers by throwing
        }

        return null;
    }
}
