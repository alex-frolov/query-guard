<?php

declare(strict_types=1);

namespace QueryGuard\Attribute;

/**
 * Turns one rule off for a test or a class.
 *
 *     #[IgnoreRule('n-plus-one')]
 *     public function testLegacyReport(): void
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final readonly class IgnoreRule
{
    public function __construct(public string $rule)
    {
    }
}
