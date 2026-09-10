<?php

declare(strict_types=1);

namespace QueryGuard\Adapter;

/**
 * The ability to run arbitrary SQL on the same connection as the query under study.
 *
 * Tier 2 needs it: EXPLAIN has to go through the same connection and the same
 * transaction, otherwise it looks at the wrong data. Parsing the plan is
 * `PlatformDriver`'s job, not this one's.
 */
interface Explainer
{
    /**
     * @param array<array-key, mixed> $params
     *
     * @return list<array<string, mixed>>
     */
    public function run(string $sql, array $params = []): array;

    /**
     * Platform name in `PlatformDriver` terms: `mysql`, `mariadb`, `pgsql`, `sqlite`.
     */
    public function platform(): string;
}
