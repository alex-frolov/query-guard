<?php

declare(strict_types=1);

namespace QueryGuard\Adapter\Doctrine\Dbal3;

use Doctrine\DBAL\Driver\Statement as DriverStatement;
use QueryGuard\Adapter\Doctrine\Connection as BaseConnection;

final class Connection extends BaseConnection
{
    public function exec(string $sql): int
    {
        return $this->recorder->measure(
            $sql,
            [],
            $this->connectionName,
            fn (): int => parent::exec($sql),
        );
    }

    protected function wrapStatement(DriverStatement $statement, string $sql): DriverStatement
    {
        return new Statement($statement, $this->recorder, $sql, $this->connectionName);
    }
}
