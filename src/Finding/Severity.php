<?php

declare(strict_types=1);

namespace QueryGuard\Finding;

enum Severity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Error = 'error';
}
