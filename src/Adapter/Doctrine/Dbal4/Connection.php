<?php

declare(strict_types=1);

namespace QueryGuard\Adapter\Doctrine\Dbal4;

use Doctrine\DBAL\Driver\Statement as DriverStatement;
use QueryGuard\Adapter\Doctrine\Connection as BaseConnection;

final class Connection extends BaseConnection
{
    public function exec(string $sql): int|string
    {
        return $this->recorder->measure(
            $sql,
            [],
            $this->connectionName,
            fn (): int|string => parent::exec($sql),
        );
    }

    protected function wrapStatement(DriverStatement $statement, string $sql): DriverStatement
    {
        return new Statement($statement, $this->recorder, $sql, $this->connectionName);
    }
}
