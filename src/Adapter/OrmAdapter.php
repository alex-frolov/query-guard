<?php

declare(strict_types=1);

namespace QueryGuard\Adapter;

use QueryGuard\Collector\QueryCollector;

/**
 * Axis 1: how queries are intercepted and what is known about where they came from.
 *
 * Adapters hook in at different moments, and that is the subtle part: a DBAL middleware
 * goes into the application's configuration before the connection is created, while
 * `DB::listen` runs after the application boots — and Laravel rebuilds the application
 * for every test. That is why `install()` must be **idempotent**: the extension calls
 * it once at start-up and again before every test.
 */
interface OrmAdapter
{
    /**
     * Whether this ORM is present in the project. Decided by `class_exists`, no configuration.
     */
    public static function supports(): bool;

    /**
     * Short name for the summary: `doctrine`, `eloquent`.
     */
    public function name(): string;

    public function install(QueryCollector $collector): void;

    /**
     * Whether the interception actually took. For Doctrine the answer stays negative
     * until the middleware sees its first connection — and the summary has to say so
     * out loud rather than stay quiet.
     */
    public function isInstalled(): bool;

    /**
     * Where to run EXPLAIN. Tier 2 only; `null` is a perfectly normal answer.
     */
    public function explainer(): ?Explainer;

    /**
     * What to do when interception failed. Printed in the summary, so the hint has to
     * be specific — otherwise "it does not work" turns into "no idea what to fix".
     */
    public function installationHint(): string;
}
