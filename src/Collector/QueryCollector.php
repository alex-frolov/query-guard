<?php

declare(strict_types=1);

namespace QueryGuard\Collector;

use QueryGuard\Query\QueryEvent;

/**
 * The seam between an ORM adapter and the core.
 *
 * Each adapter decides for itself how and when to hook in — a DBAL middleware goes in
 * before the connection is created, `DB::listen` after the application boots — but they
 * all write here. The PHPUnit extension knows nothing about how any of them installed.
 *
 * Public for calling, not for implementing: code outside the package reaches the
 * collector through `QueryGuard::collector()` and has no way to put its own in place.
 * A method may be added in a minor release.
 */
interface QueryCollector
{
    public function record(QueryEvent $event): void;

    /**
     * Whether anything is being recorded right now.
     *
     * Adapters need this: capturing a stack costs on every query, and outside the
     * boundaries of a test there is no reason to pay.
     */
    public function isRecording(): bool;
}
