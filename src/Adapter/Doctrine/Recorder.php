<?php

declare(strict_types=1);

namespace QueryGuard\Adapter\Doctrine;

use QueryGuard\Adapter\QueryEnricher;
use QueryGuard\Collector\QueryCollector;
use QueryGuard\Query\CallsiteResolver;
use QueryGuard\Query\QueryEvent;
use QueryGuard\QueryGuard;

/**
 * The part shared by the interceptors: timing, capturing the stack and recording.
 *
 * The stack is only captured while the collector is actually recording. Outside a test
 * — bootstrap, the gaps between tests — `debug_backtrace` is never called, otherwise
 * every query of the entire run would pay for it.
 *
 * The stack is consumed here and thrown away: the enricher reads it, the callsite is
 * resolved from it, and neither the frames nor the objects inside them reach the event.
 * See `QueryEvent` for the measurement behind that.
 */
final class Recorder
{
    /**
     * How deep a stack to capture.
     *
     * 50 frames is not enough: in a Symfony controller test the kernel sits between the
     * query and the application code, and a truncated stack silently loses the callsite
     * — findings then arrive without `file:line`.
     */
    private const STACK_LIMIT = 200;

    private readonly CallsiteResolver $callsiteResolver;

    public function __construct(
        private readonly ?QueryEnricher $enricher = null,
        private readonly ?QueryCollector $collector = null,
        ?CallsiteResolver $callsiteResolver = null,
    ) {
        // the middleware is built by the application's container, long before the
        // extension exists, so there is nobody to inject a configured resolver — and the
        // extension builds the very same default one
        $this->callsiteResolver = $callsiteResolver ?? CallsiteResolver::default();
    }

    public function collector(): QueryCollector
    {
        return $this->collector ?? QueryGuard::collector();
    }

    /**
     * @template T
     *
     * @param callable(): T           $execute
     * @param array<array-key, mixed> $params
     *
     * @return T
     */
    public function measure(string $sql, array $params, string $connection, callable $execute): mixed
    {
        $collector = $this->collector();

        if (!$collector->isRecording()) {
            return $execute();
        }

        // enrichment needs the objects in the frames (that is how a PersistentCollection
        // or a proxy becomes visible), but they never reach the event — see QueryEnricher
        $stack = debug_backtrace(\DEBUG_BACKTRACE_PROVIDE_OBJECT | \DEBUG_BACKTRACE_IGNORE_ARGS, self::STACK_LIMIT);
        $annotations = $this->enricher?->annotate($stack) ?? [];
        $callsite = $this->callsiteResolver->resolve($stack);
        unset($stack);

        $startedAt = hrtime(true);

        try {
            return $execute();
        } finally {
            $collector->record(new QueryEvent(
                sql: $sql,
                params: $params,
                durationMs: (hrtime(true) - $startedAt) / 1_000_000,
                connection: $connection,
                annotations: $annotations,
                callsite: $callsite,
            ));
        }
    }
}
