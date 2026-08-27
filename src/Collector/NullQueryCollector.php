<?php

declare(strict_types=1);

namespace QueryGuard\Collector;

use QueryGuard\Query\QueryEvent;

/**
 * The null object for when the extension is not loaded.
 *
 * An adapter lives in the application's configuration and is always active, whereas the
 * extension only exists under PHPUnit. Without this stub an ordinary application run
 * would fail on its very first query.
 */
final class NullQueryCollector implements QueryCollector
{
    public function record(QueryEvent $event): void
    {
    }

    public function isRecording(): bool
    {
        return false;
    }
}
