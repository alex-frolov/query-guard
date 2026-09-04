<?php

declare(strict_types=1);

namespace QueryGuard\Query;

/**
 * The place in the application code a query left from.
 */
final readonly class Callsite implements \Stringable
{
    public function __construct(
        public string $file,
        public int $line,
        public ?string $function = null,
    ) {
    }

    public function __toString(): string
    {
        return $this->file.':'.$this->line;
    }
}
