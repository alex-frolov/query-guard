<?php

declare(strict_types=1);

namespace QueryGuard\Adapter\Doctrine;

use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement as DriverStatement;

/**
 * The part of the connection wrapper shared by both DBAL generations.
 *
 * Only what has identical signatures in DBAL 3 and 4 lives here: `prepare()` and
 * `query()`. `exec()`, whose return type diverged (`int` vs `int|string`), lives
 * in the subclasses.
 */
abstract class Connection extends AbstractConnectionMiddleware
{
    public function __construct(
        DriverConnection $wrappedConnection,
        protected readonly Recorder $recorder,
        protected readonly string $connectionName,
    ) {
        parent::__construct($wrappedConnection);
    }

    public function prepare(string $sql): DriverStatement
    {
        return $this->wrapStatement(parent::prepare($sql), $sql);
    }

    public function query(string $sql): Result
    {
        return $this->recorder->measure(
            $sql,
            [],
            $this->connectionName,
            fn (): Result => parent::query($sql),
        );
    }

    abstract protected function wrapStatement(DriverStatement $statement, string $sql): DriverStatement;
}
