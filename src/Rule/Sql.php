<?php

declare(strict_types=1);

namespace QueryGuard\Rule;

/**
 * Small SQL parsing helpers shared by several rules.
 */
final class Sql
{
    public static function shorten(string $sql, int $limit = 90): string
    {
        $normalized = (string) preg_replace('/\s+/', ' ', trim($sql));

        return mb_strlen($normalized) > $limit
            ? mb_substr($normalized, 0, $limit - 3).'...'
            : $normalized;
    }

    /**
     * Whether the query fetches many rows at once by a list of keys.
     *
     * `IN (?, ?, ?)` is the opposite of N+1 — it is how N+1 gets fixed.
     */
    public static function isBatchFetch(string $sql): bool
    {
        return 1 === preg_match('/\bIN\s*\(\s*[^)]*,[^)]*\)/i', $sql);
    }

    public static function hasLimit(string $sql): bool
    {
        return 1 === preg_match('/\bLIMIT\b/i', $sql);
    }

    public static function isSelectStar(string $sql): bool
    {
        // `SELECT *`, `SELECT t0.*`, `SELECT DISTINCT *`
        return 1 === preg_match('/^\s*SELECT\s+(?:DISTINCT\s+)?(?:[\w`"\[\]]+\.)?\*/i', $sql);
    }

    /**
     * Whether the table is mentioned in a `FROM` or a `JOIN`.
     */
    public static function touchesTable(string $sql, string $table): bool
    {
        $quoted = preg_quote($table, '/');

        return 1 === preg_match('/\b(?:FROM|JOIN)\s+["`\[]?'.$quoted.'["`\]]?\b/i', $sql);
    }
}
