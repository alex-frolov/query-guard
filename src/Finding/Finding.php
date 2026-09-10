<?php

declare(strict_types=1);

namespace QueryGuard\Finding;

use QueryGuard\Query\Callsite;
use QueryGuard\TestIdentifier;

/**
 * A single hit of a rule.
 */
final readonly class Finding
{
    /**
     * @param string $signature a stable key for the baseline — see `signature()`
     */
    public function __construct(
        public string $rule,
        public TestIdentifier $test,
        public string $message,
        public Severity $severity = Severity::Warning,
        public ?Callsite $callsite = null,
        public int $count = 1,
        public string $signature = '',
    ) {
    }

    /**
     * The key by which a finding is recognised in the baseline between runs.
     *
     * Built from the rule, the file and the fingerprint — **without the line number and
     * without the test name**. A line number moves after any edit higher up the file, a
     * test name after a rename or a move; either would reset the baseline for nothing.
     */
    public static function signature(string $rule, ?Callsite $callsite, string $fingerprint): string
    {
        return $rule.'|'.(null === $callsite ? 'unknown' : $callsite->file).'|'.$fingerprint;
    }

    /**
     * A signature — or a plain path, such as a callsite's file on its own — with the
     * project's base path stripped from the front. For a `rule|file|fingerprint`
     * signature only the middle field is touched: a blanket `str_replace` would also
     * rewrite a fingerprint that happens to contain the same text.
     *
     * Shared by the baseline and both reporters: every one of them writes paths meant to
     * travel to CI, or into a baseline file committed to the repository, where an
     * absolute path from a developer's machine means nothing.
     */
    public static function relativeTo(string $value, string $basePath): string
    {
        if ('' === $basePath) {
            return $value;
        }

        $prefix = rtrim($basePath, '/').'/';
        $parts = explode('|', $value, 3);

        if (3 !== \count($parts)) {
            return str_starts_with($value, $prefix) ? substr($value, \strlen($prefix)) : $value;
        }

        if (str_starts_with($parts[1], $prefix)) {
            $parts[1] = substr($parts[1], \strlen($prefix));
        }

        return implode('|', $parts);
    }
}
