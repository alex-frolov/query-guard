<?php

declare(strict_types=1);

namespace QueryGuard\Adapter\Doctrine;

use QueryGuard\Adapter\QueryEnricher;
use QueryGuard\Collector\QueryCollector;
use QueryGuard\Query\QueryEvent;
use QueryGuard\QueryGuard;

/**
 * The part shared by the interceptors: timing, capturing the stack and recording.
 *
 * The stack is only captured while the collector is actually recording. Outside a test
 * — bootstrap, the gaps between tests — `debug_backtrace` is never called, otherwise
 * every query of the entire run would pay for it.
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

    public function __construct(
        private readonly ?QueryEnricher $enricher = null,
        private readonly ?QueryCollector $collector = null,
    ) {
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
        $startedAt = hrtime(true);

        try {
            return $execute();
        } finally {
            $collector->record(new QueryEvent(
                sql: $sql,
                params: $params,
                durationMs: (hrtime(true) - $startedAt) / 1_000_000,
                connection: $connection,
                stack: self::withoutObjects($stack),
                annotations: $annotations,
            ));
        }
    }

    /**
     * Drops object references from the frames: a trace lives until the end of the test,
     * and holding entities in it would mean holding the whole test database in memory.
     *
     * @param list<array<string, mixed>> $stack
     *
     * @return list<array<string, mixed>>
     */
    private static function withoutObjects(array $stack): array
    {
        $slim = [];

        foreach ($stack as $frame) {
            unset($frame['object']);
            $slim[] = $frame;
        }

        return $slim;
    }
}
