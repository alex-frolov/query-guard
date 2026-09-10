<?php

declare(strict_types=1);

namespace QueryGuard\Test\Unit\Fixture;

use QueryGuard\Adapter\Explainer;

/**
 * An explainer that runs nothing and only knows which platform it stands for — which is
 * what the end-of-run summary asks it, tier 2 or no tier 2.
 */
final class FakeExplainer implements Explainer
{
    public function __construct(private readonly string $platform)
    {
    }

    public function run(string $sql, array $params = []): array
    {
        return [];
    }

    public function platform(): string
    {
        return $this->platform;
    }
}
