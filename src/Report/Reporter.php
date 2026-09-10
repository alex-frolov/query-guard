<?php

declare(strict_types=1);

namespace QueryGuard\Report;

use QueryGuard\Mode;

interface Reporter
{
    public function report(Report $report, Mode $mode): void;
}
