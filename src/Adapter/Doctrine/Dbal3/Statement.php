<?php

declare(strict_types=1);

namespace QueryGuard\Adapter\Doctrine\Dbal3;

use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\ParameterType;
use QueryGuard\Adapter\Doctrine\Statement as BaseStatement;

/**
 * DBAL 3: `bindValue()` returns bool and takes an untyped `$type`, and `execute()`
 * can take the bound values directly.
 */
final class Statement extends BaseStatement
{
    public function bindValue($param, $value, $type = ParameterType::STRING)
    {
        $this->params[$param] = $value;

        // pass the argument count through unchanged: in DBAL 3 a call without $type
        // raises a deprecation of its own, and hiding that from the application is wrong
        return \func_num_args() < 3
            ? parent::bindValue($param, $value)
            : parent::bindValue($param, $value, $type);
    }

    public function execute($params = null): Result
    {
        if (\is_array($params)) {
            $this->params = $params;
        }

        return $this->measure(fn (): Result => parent::execute($params));
    }
}
