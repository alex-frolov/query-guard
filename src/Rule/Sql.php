<?php

declare(strict_types=1);

namespace QueryGuard\Rule;

use QueryGuard\Query\SqlText;

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

    /**
     * Any single identifier as a query may spell it — quoted in any of the three
     * dialects, or bare. Used to step over the qualifiers in front of a table name.
     */
    private const IDENTIFIER = '(?:"[^"]*"|`[^`]*`|\[[^\]]*\]|[\w$]+)';

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

    /**
     * Whether a `LIMIT` appears anywhere in the statement.
     *
     * Anywhere, and deliberately not "on the outermost SELECT": telling the two apart
     * needs a parser, and `no-limit` errs towards silence on purpose. A `LIMIT` that only
     * bounds a subquery therefore hides an unbounded outer read — a false negative. The
     * alternative is a rule that fires on every windowed query a project has, and a noisy
     * rule against a `large-tables` list somebody had to write by hand gets switched off
     * the same week.
     */
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
     *
     * The configured name is matched against the **end** of the qualified name in the
     * query, so a bare `orders` also answers yes for `public.orders`, `"public"."orders"`
     * and `` `shop`.`orders` ``. That is not generosity: Doctrine qualifies by schema
     * whenever one is configured, PostgreSQL projects write `public.` by hand, and
     * `large-tables` is written in the terms a developer thinks in — a name, not a
     * spelling. A qualified `public.orders` in the configuration stays exact about the
     * schema and does not match `archive.orders`.
     *
     * A qualifier is not a table, either. `FROM shop.orders` used to answer yes for
     * `shop`, because the old pattern ended on a word boundary and a dot is one.
     */
    public static function touchesTable(string $sql, string $table): bool
    {
        $parts = self::identifierParts($table);

        if ([] === $parts) {
            return false;
        }

        $name = implode('\s*\.\s*', array_map(self::identifierPattern(...), $parts));

        // `(?:IDENTIFIER\.)*` — whatever the query puts in front (a schema, a database);
        // the lookaheads keep the match from ending mid-name or on a qualifier
        $pattern = '/\b(?:FROM|JOIN)\s+(?:'.self::IDENTIFIER.'\s*\.\s*)*'.$name.'(?!\s*\.)(?![\w$])/i';

        return 1 === preg_match($pattern, self::stripped($sql));
    }

    /**
     * The parts of a configured name: `public.orders` is two, `"my.table"` is one — a
     * dot inside quotes belongs to the name rather than separating two of them.
     *
     * @return list<string>
     */
    private static function identifierParts(string $name): array
    {
        // a branch reset group, so every spelling captures into the same slot
        preg_match_all('/(?|"([^"]*)"|`([^`]*)`|\[([^\]]*)\]|([^\s.]+))/', $name, $matches);

        return array_values(array_filter($matches[1], static fn (string $part): bool => '' !== $part));
    }

    /**
     * One part of a name as the query may spell it: quoted in any of the three dialects,
     * or bare.
     */
    private static function identifierPattern(string $part): string
    {
        $quoted = preg_quote($part, '/');

        return '(?:"'.$quoted.'"|`'.$quoted.'`|\['.$quoted.'\]|'.$quoted.')';
    }

    /**
     * The query with comments and string literals taken out.
     *
     * Delegated to `Query\SqlText`, which is also what `Fingerprint` normalises with.
     * The two used to strip differently, and a query carrying a per-request comment then
     * had one identity here and another one there.
     */
    private static function stripped(string $sql): string
    {
        return SqlText::stripped($sql);
    }
}
