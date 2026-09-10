<?php

declare(strict_types=1);

namespace QueryGuard\Adapter\Doctrine;

use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\ParameterType;
use QueryGuard\Adapter\Explainer;

/**
 * Runs EXPLAIN on the same connection as the query under study.
 *
 * "The same" is a requirement, not a convenience: test harnesses such as
 * `dama/doctrine-test-bundle` keep each test inside a transaction that is rolled back,
 * and the data only exists there. A separate connection would explain an empty database.
 */
final class DoctrineExplainer implements Explainer
{
    public function __construct(
        private readonly DriverConnection $connection,
        private readonly string $platform,
    ) {
    }

    public function platform(): string
    {
        return $this->platform;
    }

    public function run(string $sql, array $params = []): array
    {
        if ([] === $params) {
            return $this->connection->query($sql)->fetchAllAssociative();
        }

        $statement = $this->connection->prepare($sql);

        foreach (self::rebased($params) as $key => $value) {
            // Calling bindValue is compatible with DBAL 3 and 4 — only the signatures
            // diverge when overriding, not when calling (see Dbal3\Statement / Dbal4\Statement).
            //
            // Binding everything as a string is what MySQL forgives and PostgreSQL does
            // not: `WHERE int_column = $1` with a text parameter fails outright, and the
            // EXPLAIN then lands in the "failed" counter for a reason that has nothing to
            // do with the query under study.
            //
            // Written inline and without a helper on purpose: in DBAL 3 `ParameterType`
            // is a class of int constants, in DBAL 4 an enum. A method declaring
            // `: ParameterType` would be a TypeError on DBAL 3; a `match` at the call
            // site is whatever the installed version says it is.
            $statement->bindValue($key, $value, match (true) {
                null === $value => ParameterType::NULL,
                \is_int($value) => ParameterType::INTEGER,
                default => ParameterType::STRING,
            });
        }

        return $statement->execute()->fetchAllAssociative();
    }

    /**
     * Positional parameters counted from one, the way the drivers require.
     *
     * DBAL binds them that way itself, so the values a statement collected normally
     * arrive correct. Not always, though: `Dbal3\Statement::execute($params)` — the
     * deprecated but still reachable path — hands over whatever the caller passed, and
     * that is a zero-indexed list. `bindValue(0, ...)` is then rejected outright, the
     * EXPLAIN lands in the "failed" counter, and the reported reason has nothing to do
     * with the query under study. Named parameters are left exactly as they are.
     *
     * @param array<array-key, mixed> $params
     *
     * @return array<array-key, mixed>
     */
    private static function rebased(array $params): array
    {
        if (!\array_key_exists(0, $params)) {
            return $params;
        }

        foreach (array_keys($params) as $key) {
            if (!\is_int($key)) {
                // mixed keys are not something to guess about
                return $params;
            }
        }

        $rebased = [];

        foreach (array_values($params) as $index => $value) {
            $rebased[$index + 1] = $value;
        }

        return $rebased;
    }
}
