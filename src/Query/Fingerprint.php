<?php

declare(strict_types=1);

namespace QueryGuard\Query;

/**
 * A normalised form of an SQL statement: literals stripped, `IN (?, ?)` lists collapsed,
 * whitespace and case unified.
 *
 * Needed twice over: to deduplicate identical queries and to detect N+1. This is exactly
 * where the closest competitor goes wrong — it compares SQL together with the bound
 * values, and in N+1 those differ by definition.
 */
final class Fingerprint implements \Stringable
{
    private function __construct(private readonly string $value)
    {
    }

    public static function of(string $sql): self
    {
        $normalized = $sql;

        // Single quotes mean a string literal in all three target databases.
        // Double quotes are left alone: in PostgreSQL they delimit an identifier.
        $normalized = (string) preg_replace("/'(?:[^']|'')*'/", '?', $normalized);

        // Numeric literals. `\b` does not split `id_1`, so Doctrine's aliases survive.
        $normalized = (string) preg_replace('/\b\d+(?:\.\d+)?\b/', '?', $normalized);

        // IN (?, ?, ?) → IN (?): list length must not spawn different fingerprints.
        $normalized = (string) preg_replace('/\bIN\s*\(\s*\?(?:\s*,\s*\?)*\s*\)/i', 'IN (?)', $normalized);

        $normalized = (string) preg_replace('/\s+/', ' ', $normalized);
        $normalized = rtrim(trim($normalized), ';');

        return new self(strtolower($normalized));
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
