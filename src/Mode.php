<?php

declare(strict_types=1);

namespace QueryGuard;

enum Mode: string
{
    /** The default: nothing fails, a summary is printed at the end of the run. */
    case Report = 'report';

    /** Findings fail the run. */
    case Strict = 'strict';

    public static function fromString(string $value): self
    {
        return self::tryFrom(strtolower(trim($value))) ?? self::Report;
    }
}
