<?php

declare(strict_types=1);

namespace QueryGuard\Test\Unit\Fixture;

use QueryGuard\Attribute\AllowQueries;
use QueryGuard\Attribute\IgnoreRule;

#[AllowQueries(10)]
#[IgnoreRule('duplicate-query')]
final class AnnotatedSubject
{
    public function inheritsClassOptions(): void
    {
    }

    #[AllowQueries(50)]
    public function overridesThreshold(): void
    {
    }

    #[IgnoreRule('n-plus-one')]
    #[IgnoreRule('query-count')]
    public function ignoresMoreRules(): void
    {
    }
}
