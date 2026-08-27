<?php

declare(strict_types=1);

namespace QueryGuard\Platform;

/**
 * Typed access to decoded JSON.
 *
 * `EXPLAIN` output is `mixed` all the way down, and without a wrapper like this one
 * plan parsing turns into a scattering of casts, any of which can quietly produce the
 * wrong thing. Here "the wrong thing" becomes `null`, and the caller has to deal with it.
 */
final class Json
{
    /**
     * @return array<array-key, mixed>
     */
    public static function object(mixed $value, ?string $key = null): array
    {
        if (null !== $key) {
            $value = \is_array($value) ? ($value[$key] ?? null) : null;
        }

        if (!\is_array($value)) {
            return [];
        }

        /* @var array<string, mixed> $value */
        return $value;
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    public static function objects(mixed $value, string $key): array
    {
        $raw = \is_array($value) ? ($value[$key] ?? null) : null;

        if (!\is_array($raw)) {
            return [];
        }

        $items = [];

        foreach ($raw as $item) {
            if (\is_array($item)) {
                /* @var array<string, mixed> $item */
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * @param array<array-key, mixed> $node
     */
    public static function string(array $node, string $key): ?string
    {
        $value = $node[$key] ?? null;

        return \is_string($value) ? $value : null;
    }

    /**
     * @param array<array-key, mixed> $node
     */
    public static function int(array $node, string $key): ?int
    {
        $value = $node[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @param array<array-key, mixed> $node
     */
    public static function float(array $node, string $key): ?float
    {
        $value = $node[$key] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @param array<array-key, mixed> $node
     */
    public static function bool(array $node, string $key): bool
    {
        return ($node[$key] ?? false) === true;
    }

    /**
     * @param array<array-key, mixed> $node
     *
     * @return list<string>
     */
    public static function strings(array $node, string $key): array
    {
        $value = $node[$key] ?? null;

        if (!\is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, \is_string(...)));
    }
}
