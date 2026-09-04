<?php

declare(strict_types=1);

namespace QueryGuard\Query;

/**
 * A normalised form of an SQL statement: comments removed, literals stripped,
 * `IN (?, ?)` lists collapsed, whitespace and case unified.
 *
 * Needed twice over: to deduplicate identical queries and to detect N+1. This is exactly
 * where the closest competitor goes wrong — it compares SQL together with the bound
 * values, and in N+1 those differ by definition.
 *
 * **Comments go first, through the same `SqlText` the rule helpers use.** They used not
 * to, and the effect was the quietest failure this package had: tracing middleware and
 * sqlcommenter append a per-request comment to every statement, so the same query arrived
 * with a different fingerprint every time. `n-plus-one` and `duplicate-query` group by
 * fingerprint — on such a project both saw a suite full of unique queries and reported
 * nothing at all.
 */
final class Fingerprint implements \Stringable
{
    private function __construct(private readonly string $value)
    {
    }

    public static function of(string $sql): self
    {
        // Comments out, string literals to `?`, in one left-to-right pass. Single quotes
        // mean a literal in all three target databases; double quotes are left alone,
        // since in PostgreSQL they delimit an identifier.
        $normalized = SqlText::placeholders($sql);

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
