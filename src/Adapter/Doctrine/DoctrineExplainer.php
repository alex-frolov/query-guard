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

        foreach ($params as $key => $value) {
            // calling bindValue is compatible with DBAL 3 and 4 — only the signatures
            // diverge when overriding, not when calling (see Dbal3\Statement / Dbal4\Statement)
            $statement->bindValue($key, $value, ParameterType::STRING);
        }

        return $statement->execute()->fetchAllAssociative();
    }
}
