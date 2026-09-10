<?php

declare(strict_types=1);

namespace QueryGuard\Collector;

enum Phase
{
    /** Outside a test: bootstrap, data providers, the gaps between tests. */
    case Idle;

    /** Between `Test\PreparationStarted` and `Test\Prepared` — that is, `setUp()` and fixtures. */
    case Fixtures;

    /** Between `Test\Prepared` and `Test\Finished` — the test body. This is the trace. */
    case Test;
}
