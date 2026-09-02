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

    /**
     * A string literal, a block comment or a line comment — whichever opens first.
     *
     * One alternation rather than three passes, and that is a correctness requirement
     * rather than a tidying: see `stripped()`.
     */
    private const LITERAL_OR_COMMENT = "#'(?:[^']|'')*'|/\*.*?\*/|--[^\n]*#s";

    /**
     * Any single identifier as a query may spell it — quoted in any of the three
     * dialects, or bare. Used to step over the qualifiers in front of a table name.
     */
    private const IDENTIFIER = '(?:"[^"]*"|`[^`]*`|\[[^\]]*\]|[\w$]+)';

    /**
     * How many distinct queries to remember before starting the cache over.
     *
     * A bound is needed because the key is the raw SQL: a suite building endless
     * distinct statements would otherwise grow this for the whole run.
     */
    private const CACHE_LIMIT = 2000;

    /** @var array<string, string> */
    private static array $stripped = [];

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
     * A literal becomes an empty one rather than disappearing, so the surrounding
     * structure — and therefore the shape of an `IN` list — survives. Double quotes are
     * left alone: in PostgreSQL they delimit an identifier, and a table name has to stay
     * findable for `touchesTable()`. `#` is not treated as a comment either: it is a
     * MySQL-only spelling that ORMs do not emit, while PostgreSQL uses it inside
     * operators.
     *
     * **One pass, not three.** Stripping comments first and literals afterwards looks
     * equivalent and is not: `WHERE body = 'a -- b'` loses its closing quote to the
     * line-comment pass, and nothing after it is recognisable any more. That is not a
     * cosmetic error — `isBatchFetch()` then answers "no" for a real `IN (?, ?)` list,
     * and a false negative there is a **false positive in the flagship rule**. A single
     * alternation scans left to right, so whichever construct opens first wins, which is
     * how the database reads it too.
     *
     * The result is memoised: the helpers above are called once per rule per query, and
     * `touchesTable()` once per configured table on top of that.
     */
    private static function stripped(string $sql): string
    {
        if (\array_key_exists($sql, self::$stripped)) {
            return self::$stripped[$sql];
        }

        if (\count(self::$stripped) >= self::CACHE_LIMIT) {
            self::$stripped = [];
        }

        return self::$stripped[$sql] = (string) preg_replace_callback(
            self::LITERAL_OR_COMMENT,
            static fn (array $match): string => "'" === $match[0][0] ? "''" : ' ',
            $sql,
        );
    }
}
