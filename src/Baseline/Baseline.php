<?php

declare(strict_types=1);

namespace QueryGuard\Baseline;

use QueryGuard\Finding\Finding;

/**
 * The list of already known findings: what is old stays quiet, what is new fails.
 *
 * Without a baseline, the first install on a legacy project turns hundreds of tests red
 * and the tool is removed the same day. The point is not to hide problems but to draw a
 * line and stop new ones from crossing it.
 *
 * The key is `rule|file|fingerprint`, with no line number and no test name: both move
 * for harmless reasons and would reset the baseline for nothing.
 */
final class Baseline
{
    /**
     * @param array<string, array<array-key, mixed>> $entries
     */
    private function __construct(
        private array $entries,
        private readonly string $basePath = '',
    ) {
    }

    public static function empty(string $basePath = ''): self
    {
        return new self([], $basePath);
    }

    public static function fromFile(string $path, string $basePath = ''): self
    {
        if (!is_file($path)) {
            return self::empty($basePath);
        }

        $raw = file_get_contents($path);

        if (false === $raw) {
            return self::empty($basePath);
        }

        try {
            $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return self::empty($basePath);
        }

        $raw = \is_array($decoded) ? ($decoded['findings'] ?? null) : null;
        $entries = [];

        foreach (\is_array($raw) ? $raw : [] as $signature => $entry) {
            if (\is_string($signature) && \is_array($entry)) {
                /* @var array<string, mixed> $entry */
                $entries[$signature] = $entry;
            }
        }

        return new self($entries, $basePath);
    }

    public function contains(Finding $finding): bool
    {
        return '' !== $finding->signature && isset($this->entries[$this->key($finding)]);
    }

    public function add(Finding $finding): void
    {
        if ('' === $finding->signature) {
            return;
        }

        $this->entries[$this->key($finding)] ??= [
            'rule' => $finding->rule,
            'place' => $this->relative(null !== $finding->callsite ? (string) $finding->callsite : ''),
            'sample' => $finding->message,
        ];
    }

    /**
     * Paths in the keys are relative.
     *
     * The baseline file is committed to the repository, and the project root differs
     * between a developer's machine and CI. With an absolute path the baseline would
     * work nowhere except the machine that generated it.
     */
    private function key(Finding $finding): string
    {
        return $this->relative($finding->signature);
    }

    /**
     * A signature is `rule|file|fingerprint`, so the base path is stripped from the
     * middle field only — a blanket `str_replace` would also rewrite a fingerprint that
     * happens to contain the same text.
     */
    private function relative(string $value): string
    {
        if ('' === $this->basePath) {
            return $value;
        }

        $prefix = rtrim($this->basePath, '/').'/';
        $parts = explode('|', $value, 3);

        if (3 !== \count($parts)) {
            return str_starts_with($value, $prefix) ? substr($value, \strlen($prefix)) : $value;
        }

        if (str_starts_with($parts[1], $prefix)) {
            $parts[1] = substr($parts[1], \strlen($prefix));
        }

        return implode('|', $parts);
    }

    public function count(): int
    {
        return \count($this->entries);
    }

    public function save(string $path): bool
    {
        ksort($this->entries);

        $payload = [
            'generated-at' => date('c'),
            'comment' => 'query-guard baseline: the findings listed here do not fail the run. '
                .'Commit this file; to regenerate it, run with QUERY_GUARD_GENERATE_BASELINE=1.',
            'findings' => $this->entries,
        ];

        $json = json_encode($payload, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        if (false === $json) {
            return false;
        }

        $directory = \dirname($path);

        if (!is_dir($directory)) {
            return false;
        }

        return false !== file_put_contents($path, $json.\PHP_EOL);
    }
}
