<?php

declare(strict_types=1);

namespace QueryGuard\Adapter\Eloquent;

use Illuminate\Database\Connection;
use QueryGuard\Adapter\Explainer;

/**
 * Runs EXPLAIN on the same Eloquent connection as the query under study.
 *
 * **Takes a resolver, not a `Connection`.** `PlanProvider::sourceFor()` resolves an
 * `Explainer` once per connection name and keeps it for the rest of the run — a design
 * that assumes the connection behind that name stays valid for as long as `dama/doctrine-
 * test-bundle` keeps its single suite-wide connection open. Eloquent breaks that
 * assumption: `RefreshDatabase`/`DatabaseTransactions` tear the whole application down
 * between tests, so binding to one `Connection` object here would hand later tests a
 * reconnect callback tied to an already-destroyed container — confirmed on a live Laravel
 * 13 run (`notification-service`), where it surfaced as `Target class [config] does not
 * exist` on every EXPLAIN whose fingerprint was first seen in a later test. Resolving the
 * connection at call time, from `EloquentAdapter`'s registry, always reaches whichever
 * connection is live right now instead of whichever one happened to be first.
 *
 * `$useReadPdo: false` is not a style choice either: `select()` defaults to the read PDO,
 * and outside a transaction that can be a different connection entirely (a read replica).
 * Forcing the write PDO is what makes "the same connection" true unconditionally, rather
 * than relying on Eloquent's own transaction-count check (`getReadPdo()` already falls
 * back to the write PDO once a transaction is open, but a connection that never started
 * one is exactly the case this guards against).
 *
 * Rows come back as `stdClass` — Eloquent sets `PDO::FETCH_OBJ` on every prepared
 * statement (`Connection::prepared()`), EXPLAIN included. `Explainer::run()` promises
 * arrays keyed by column name, so each row is cast before it goes any further.
 */
final class EloquentExplainer implements Explainer
{
    /**
     * @param \Closure(): Connection $connection
     */
    public function __construct(private readonly \Closure $connection)
    {
    }

    public function platform(): string
    {
        return ($this->connection)()->getDriverName();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function run(string $sql, array $params = []): array
    {
        $rows = ($this->connection)()->select($sql, $params, false);

        $result = [];

        foreach ($rows as $row) {
            /** @var array<string, mixed> $asArray */
            $asArray = (array) $row;
            $result[] = $asArray;
        }

        return $result;
    }
}
