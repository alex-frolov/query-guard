<?php

declare(strict_types=1);

namespace QueryGuard\Rule;

/**
 * Small SQL parsing helpers shared by several rules.
 *
 * Every helper that looks for a keyword or a structure works on the *stripped* form of
 * the query — comments and string literals removed. Without that the helpers answer
 * questions about text rather than about SQL: `WHERE name = 'LIMIT'` used to count as a
 * query with a `LIMIT`, and an `IN` list inside a comment as a batch fetch.
 */
final class Sql
{
    /**
     * A list of bound keys: `IN (?, ?)`, `IN (:a, :b)`, `IN ($1, $2)`, `IN (1, 2)`.
     *
     * Two or more elements, every one of them a placeholder or a number. A list of
     * string literals is deliberately NOT matched — see `isBatchFetch()`.
     */
    private const KEY_LIST = '/\bIN\s*\(\s*(?:\?|:\w+|\$\d+|-?\d+)(?:\s*,\s*(?:\?|:\w+|\$\d+|-?\d+))+\s*\)/i';

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
     * `IN (?, ?, ?)` is the opposite of N+1 — it is how N+1 gets fixed, so the
     * `n-plus-one` rule skips such queries. Which makes a false positive here a **false
     * negative in the flagship rule**, and that is why the test is narrow:
     *
     * - only placeholders and numbers count as keys. `WHERE status IN ('new','paid')
     *   AND user_id = ?` is a static enum filter standing next to an ordinary lookup —
     *   repeated in a loop it is textbook N+1, and a looser test used to hide it;
     * - a subquery (`IN (SELECT a, b FROM t)`) is not a key list either;
     * - at least two elements: `IN (?)` fetches one row like any other lookup.
     */
    public static function isBatchFetch(string $sql): bool
    {
        return 1 === preg_match(self::KEY_LIST, self::stripped($sql));
    }

    public static function hasLimit(string $sql): bool
    {
        return 1 === preg_match('/\bLIMIT\b/i', self::stripped($sql));
    }

    public static function isSelectStar(string $sql): bool
    {
        // `SELECT *`, `SELECT t0.*`, `SELECT DISTINCT *`
        return 1 === preg_match('/^\s*SELECT\s+(?:DISTINCT\s+)?(?:[\w`"\[\]]+\.)?\*/i', self::stripped($sql));
    }

    /**
     * Whether the table is mentioned in a `FROM` or a `JOIN`.
     */
    public static function touchesTable(string $sql, string $table): bool
    {
        $quoted = preg_quote($table, '/');

        return 1 === preg_match('/\b(?:FROM|JOIN)\s+["`\[]?'.$quoted.'["`\]]?\b/i', self::stripped($sql));
    }

    /**
     * The query with comments and string literals taken out.
     *
     * A literal becomes an empty one rather than disappearing, so the surrounding
     * structure — and therefore the shape of an `IN` list — survives. Double quotes are
     * left alone: in PostgreSQL they delimit an identifier, and a table name has to stay
     * findable for `touchesTable()`. `#` is not treated as a comment either: it is a
     * MySQL-only spelling that ORMs do not emit, while PostgreSQL uses it inside
     * operators.
     */
    private static function stripped(string $sql): string
    {
        // ORMs put their own hints in comments, and a keyword inside one says nothing
        // about what the database is going to do
        $sql = (string) preg_replace('#/\*.*?\*/#s', ' ', $sql);
        $sql = (string) preg_replace('/--[^\n]*/', ' ', $sql);

        return (string) preg_replace("/'(?:[^']|'')*'/", "''", $sql);
    }
}
