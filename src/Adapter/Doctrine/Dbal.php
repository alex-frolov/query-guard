<?php

declare(strict_types=1);

namespace QueryGuard\Adapter\Doctrine;

use Doctrine\DBAL\ParameterType;

/**
 * Telling DBAL 3 and DBAL 4 apart.
 *
 * Both have to be supported: plenty of live projects still run Doctrine ORM 2 on
 * DBAL 3. The test is a fact taken from the code rather than a version number from
 * composer: in DBAL 4 `ParameterType` became an enum, and that is precisely why the
 * signatures of `Statement::bindValue()` and `Statement::execute()` diverged.
 */
final class Dbal
{
    public static function isVersion4(): bool
    {
        return enum_exists(ParameterType::class);
    }
}
