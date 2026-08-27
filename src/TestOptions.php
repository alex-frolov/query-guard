<?php

declare(strict_types=1);

namespace QueryGuard;

use QueryGuard\Attribute\AllowQueries;
use QueryGuard\Attribute\IgnoreRule;

/**
 * Options taken from the attributes of a test and its class.
 *
 * Read once per test and stored on the trace, so a rule gets everything it needs from
 * `Trace` without reaching for reflection itself.
 */
final readonly class TestOptions
{
    /**
     * @param list<string> $ignoredRules
     */
    public function __construct(
        public ?int $allowQueries = null,
        public array $ignoredRules = [],
    ) {
    }

    public static function none(): self
    {
        return new self();
    }

    public static function fromTest(?string $className, ?string $methodName): self
    {
        if (null === $className || !class_exists($className)) {
            return self::none();
        }

        $reflectors = [new \ReflectionClass($className)];

        if (null !== $methodName && method_exists($className, $methodName)) {
            // the method comes last: it overrides the class
            $reflectors[] = new \ReflectionMethod($className, $methodName);
        }

        $allowQueries = null;
        $ignoredRules = [];

        foreach ($reflectors as $reflector) {
            foreach ($reflector->getAttributes(AllowQueries::class) as $attribute) {
                $allowQueries = $attribute->newInstance()->maximum;
            }

            foreach ($reflector->getAttributes(IgnoreRule::class) as $attribute) {
                $ignoredRules[] = $attribute->newInstance()->rule;
            }
        }

        return new self($allowQueries, array_values(array_unique($ignoredRules)));
    }

    public function isIgnored(string $rule): bool
    {
        return \in_array($rule, $this->ignoredRules, true);
    }
}
