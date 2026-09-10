<?php

declare(strict_types=1);

namespace QueryGuard\Attribute;

/**
 * Raises the `query-count` budget for a single test or a whole class.
 *
 *     #[AllowQueries(50)]
 *     public function testImportsLargeFile(): void
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final readonly class AllowQueries
{
    public function __construct(public int $maximum)
    {
    }
}
