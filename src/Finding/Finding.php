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

    public function withSignature(string $signature): self
    {
        return new self(
            $this->rule,
            $this->test,
            $this->message,
            $this->severity,
            $this->callsite,
            $this->count,
            $signature,
        );
    }
}
