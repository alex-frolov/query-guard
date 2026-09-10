<?php

declare(strict_types=1);

namespace QueryGuard;

/**
 * A test in query-guard's own terms.
 *
 * A value of our own rather than `PHPUnit\Event\Code\Test`: neither the core nor the
 * rules should depend on the runner, which is an axis that varies independently of the
 * ORM and of the database platform.
 */
final readonly class TestIdentifier implements \Stringable
{
    public function __construct(
        public string $id,
        public string $label,
        public ?string $className = null,
        public ?string $methodName = null,
    ) {
    }

    public function __toString(): string
    {
        return $this->label;
    }
}
