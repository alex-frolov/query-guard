<?php

declare(strict_types=1);

namespace QueryGuard\Adapter\Doctrine;

use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement as DriverStatement;

/**
 * The part of the prepared-statement wrapper shared by both DBAL generations.
 *
 * Bound values accumulate here. Unlike Doctrine's own logging middleware, the
 * measurement wraps AROUND execution rather than happening before it — that is the only
 * way to get a duration and a truthful order of queries.
 */
abstract class Statement extends AbstractStatementMiddleware
{
    /** @var array<array-key, mixed> */
    protected array $params = [];

    public function __construct(
        DriverStatement $wrappedStatement,
        protected readonly Recorder $recorder,
        protected readonly string $sql,
        protected readonly string $connectionName,
    ) {
        parent::__construct($wrappedStatement);
    }

    /**
     * @param callable(): Result $execute
     */
    protected function measure(callable $execute): Result
    {
        return $this->recorder->measure($this->sql, $this->params, $this->connectionName, $execute);
    }
}
