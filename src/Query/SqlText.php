<?php

declare(strict_types=1);

namespace QueryGuard\Query;

/**
 * The one place that knows where a comment ends and a string literal begins.
 *
 * It exists because there used to be two answers to that question. `Rule\Sql` stripped
 * comments before asking anything about a query, while `Fingerprint` did not — so a
 * statement carrying a per-request comment, as tracing middleware and sqlcommenter
 * append, produced a different fingerprint on every run of the same query.
 * `n-plus-one` and `duplicate-query` group by fingerprint, so both simply stopped
 * seeing anything, and stopped without a word.
 *
 * **One pass, not three.** Stripping comments first and literals afterwards looks
 * equivalent and is not: `WHERE body = 'a -- b'` loses its closing quote to the
 * line-comment pass, and nothing after it is recognisable any more. A single alternation
 * scans left to right, so whichever construct opens first wins — which is how the
 * database reads it too.
 *
 * Double quotes are left alone: in PostgreSQL they delimit an identifier, and a table
 * name has to stay findable for `Rule\Sql::touchesTable()`. `#` is not treated as a
 * comment either: it is a MySQL-only spelling that ORMs do not emit, while PostgreSQL
 * uses it inside operators.
 *
 * A literal ends at a doubled quote (`''`, the SQL-standard escape) or a backslash
 * escape (`\'`, what MySQL honours by default without `NO_BACKSLASH_ESCAPES`) —
 * whichever the query actually used. Reading only the first would end the literal
 * early on the other spelling, and everything after it — a real comment marker or the
 * next literal — stops being recognisable, the exact silent-misdiagnosis shape this
 * class exists to close off.
 */
final class SqlText
{
    /**
     * A string literal, a block comment or a line comment — whichever opens first.
     */
    private const LITERAL_OR_COMMENT = "#'(?:[^'\\\\]|''|\\\\.)*'|/\*.*?\*/|--[^\n]*#s";

    /**
     * A string literal on its own, captured so `preg_split()` can hand it back untouched.
     */
    private const LITERAL = "#('(?:[^'\\\\]|''|\\\\.)*')#s";

    /**
     * How many distinct queries to remember before starting the cache over.
     *
     * A bound is needed because the key is the raw SQL: a suite building endless
     * distinct statements would otherwise grow this for the whole run.
     */
    private const CACHE_LIMIT = 2000;

    /** @var array<string, string> */
    private static array $cache = [];

    /**
     * Comments taken out, string literals emptied.
     *
     * A literal becomes an empty one rather than disappearing, so the surrounding
     * structure — and therefore the shape of an `IN` list — survives. This is the form
     * the rule helpers ask their questions about: without it they answer questions about
     * text rather than about SQL, and `WHERE name = 'LIMIT'` counts as a query with a
     * `LIMIT`.
     */
    public static function stripped(string $sql): string
    {
        return self::rewrite($sql, "''");
    }

    /**
     * Comments taken out, string literals replaced by a single placeholder.
     *
     * What `Fingerprint` needs: the values are exactly what differs between the repeats
     * of an N+1, so they cannot be part of the identity of a query shape.
     */
    public static function placeholders(string $sql): string
    {
        return self::rewrite($sql, '?');
    }

    /**
     * Comments taken out, string literals left exactly as they were.
     *
     * What `QueryEvent::shape()` needs: a duplicate is an identical query with identical
     * values, so a literal cannot be blanked out the way `stripped()` and `placeholders()`
     * blank it — only the comment a tracer stapled on top (and the gap its removal
     * leaves) should go.
     *
     * Literals are split off first, by the same rule `LITERAL_OR_COMMENT` uses to
     * recognise one, so a comment is only ever searched for in text already known to be
     * outside a literal — the "one pass" guarantee, done as a split instead of a single
     * alternation because a literal's contents must survive untouched, including
     * whatever whitespace is inside it.
     */
    public static function withoutComments(string $sql): string
    {
        $key = "\0no-comments".$sql;

        if (\array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }

        if (\count(self::$cache) >= self::CACHE_LIMIT) {
            self::$cache = [];
        }

        $chunks = preg_split(self::LITERAL, $sql, -1, \PREG_SPLIT_DELIM_CAPTURE);

        if (false === $chunks) {
            return self::$cache[$key] = $sql;
        }

        foreach ($chunks as $i => $chunk) {
            if (1 === $i % 2) {
                continue; // a literal, captured by the group — left exactly as it was
            }

            $chunks[$i] = (string) preg_replace(['#/\*.*?\*/|--[^\n]*#s', '#\s+#'], ' ', $chunk);
        }

        return self::$cache[$key] = trim(implode('', $chunks));
    }

    /**
     * The result is memoised: several rule helpers ask about the same query, and
     * `touchesTable()` asks once per configured table on top of that.
     */
    private static function rewrite(string $sql, string $literal): string
    {
        $key = $literal.$sql;

        if (\array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }

        if (\count(self::$cache) >= self::CACHE_LIMIT) {
            self::$cache = [];
        }

        return self::$cache[$key] = (string) preg_replace_callback(
            self::LITERAL_OR_COMMENT,
            static fn (array $match): string => "'" === $match[0][0] ? $literal : ' ',
            $sql,
        );
    }
}
