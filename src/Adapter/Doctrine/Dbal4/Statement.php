<?php

declare(strict_types=1);

namespace QueryGuard\Adapter\Doctrine\Dbal4;

use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\ParameterType;
use QueryGuard\Adapter\Doctrine\Statement as BaseStatement;

/**
 * DBAL 4: `bindValue()` returns nothing and requires the `ParameterType` enum, and
 * `execute()` takes no bound values.
 */
final class Statement extends BaseStatement
{
    public function bindValue(int|string $param, mixed $value, ParameterType $type): void
    {
        $this->params[$param] = $value;

        parent::bindValue($param, $value, $type);
    }

    public function execute(): Result
    {
        return $this->measure(fn (): Result => parent::execute());
    }
}
