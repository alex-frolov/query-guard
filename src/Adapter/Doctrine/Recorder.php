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
 *
 * @internal
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

    /** By name: the adapter must not need `doctrine/orm`, only `doctrine/dbal`. */
    private const UNIT_OF_WORK = 'Doctrine\\ORM\\UnitOfWork';

    private readonly CallsiteResolver $callsiteResolver;

    /**
     * Transactions begun so far, across every connection — the identity of a commit.
     *
     * Static because there is a recorder per middleware, and two connections must never
     * hand out the same number.
     */
    private static int $transactions = 0;

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

    /**
     * A transaction began on the driver connection.
     *
     * `UnitOfWork::commit()` always opens one, so this is where one flush ends and the
     * next begins — even when two flushes follow each other with nothing in between.
     */
    public function transactionStarted(): void
    {
        ++self::$transactions;
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
        // Inside a test transaction that is rolled back afterwards (`dama/doctrine-test-bundle`)
        // the driver connection is already in a transaction, and DBAL nests every further
        // one as a savepoint — which reaches the driver as a statement, not as a begin.
        //
        // Those statements are the harness, not the application: in production the same
        // `flush()` opens a plain transaction, whose begin and commit never pass through
        // here. Recorded, they put a `SAVEPOINT doctrine_2` / `RELEASE SAVEPOINT doctrine_2`
        // pair around every flush, and the number in the name made each level a shape of
        // its own: on one suite a `wrapInTransaction()` line was a `query-in-loop` finding
        // made of nothing but savepoints. They are executed and left out of the trace.
        $savepoint = self::savepoint($sql);

        if (null !== $savepoint) {
            if ('begin' === $savepoint) {
                $this->transactionStarted();
            }

            return $execute();
        }

        $stack = debug_backtrace(\DEBUG_BACKTRACE_PROVIDE_OBJECT | \DEBUG_BACKTRACE_IGNORE_ARGS, self::STACK_LIMIT);
        $annotations = $this->enricher?->annotate($stack) ?? [];

        if (self::insideCommit($stack)) {
            $annotations[DoctrineEnricher::COMMIT] = self::$transactions;
        }

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

    /**
     * `begin` for `SAVEPOINT x` / `SAVE TRANSACTION x`, `end` for releasing or rolling
     * back to one, null for anything else — spelled the way DBAL's platforms spell them.
     */
    private static function savepoint(string $sql): ?string
    {
        $statement = ltrim($sql);

        if (1 === preg_match('/^(?:SAVEPOINT|SAVE\s+TRANSACTION)\s/i', $statement)) {
            return 'begin';
        }

        if (1 === preg_match('/^(?:RELEASE\s+SAVEPOINT|ROLLBACK\s+TO\s+(?:SAVEPOINT\s+)?|ROLLBACK\s+TRANSACTION\s+\w)/i', $statement)) {
            return 'end';
        }

        return null;
    }

    /**
     * Whether the query was written by `UnitOfWork::commit()` — a `flush()`.
     *
     * The unit of work writes every pending change from that one call, so a flush of
     * twenty entities is twenty statements from one line of application code. Counted
     * query by query that looks like a loop; it is one operation.
     *
     * @param list<array<string, mixed>> $stack
     */
    private static function insideCommit(array $stack): bool
    {
        foreach ($stack as $frame) {
            if ('commit' === ($frame['function'] ?? null) && self::UNIT_OF_WORK === ($frame['class'] ?? null)) {
                return true;
            }
        }

        return false;
    }
}
