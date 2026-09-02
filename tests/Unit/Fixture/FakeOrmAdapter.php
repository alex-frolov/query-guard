<?php

declare(strict_types=1);

namespace QueryGuard\Test\Unit\Fixture;

use QueryGuard\Adapter\OrmAdapter;
use QueryGuard\Collector\QueryCollector;

/**
 * An ORM adapter that intercepts nothing and only counts what was asked of it.
 *
 * Written against the published seam on purpose: `install()` has to be idempotent and is
 * called again before every test, and the summary tells apart "no ORM", "an ORM that
 * failed to hook in" and "an ORM that hooked in and saw nothing". All three answers come
 * from this interface, so all three are shaped here.
 */
final class FakeOrmAdapter implements OrmAdapter
{
    public int $installations = 0;

    public function __construct(
        private readonly string $name = 'fake',
        private readonly bool $installed = true,
    ) {
    }

    public static function supports(): bool
    {
        return true;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function install(QueryCollector $collector): void
    {
        ++$this->installations;
    }

    public function isInstalled(): bool
    {
        return $this->installed;
    }

    public function explainers(): array
    {
        return [];
    }

    public function installationHint(): string
    {
        return sprintf('%s: put the middleware in the test configuration.', $this->name);
    }
}
