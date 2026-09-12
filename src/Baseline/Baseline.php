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
 *
 * @internal
 */
final class Baseline
{
    /**
     * The shape of the file, written into it as `format-version`.
     *
     * The file is committed and outlives the release that wrote it, so a reader has to
     * be able to tell a file it understands from one it would only misread. Raised on an
     * incompatible change alone — a field removed or renamed, a key that means something
     * else. A new field is not one: a reader that does not know it ignores it.
     *
     * A file without the field predates it and is format 1.
     */
    public const FORMAT_VERSION = 1;

    /**
     * Entries matched during this run.
     *
     * A baseline only ever grows otherwise: a finding gets fixed, its entry stays, and
     * from then on the file silences something that no longer exists. Nobody discovers
     * that on their own, so the run has to say it.
     *
     * @var array<string, true>
     */
    private array $matched = [];

    /**
     * @param array<string, array<array-key, mixed>> $entries
     * @param string                                 $platform         the platform the file was generated on — see `platform()`
     * @param string|null                            $unreadableFormat see `unreadableFormat()`
     */
    private function __construct(
        private array $entries,
        private readonly string $basePath = '',
        private readonly string $platform = '',
        private readonly ?string $unreadableFormat = null,
    ) {
    }

    public static function empty(string $basePath = ''): self
    {
        return new self([], $basePath);
    }

    /**
     * The database platform this file was generated on, or an empty string for a file
     * written before the field existed — or by a run that never opened a connection.
     *
     * A signature holds the fingerprint of the statement, and the same DQL becomes
     * different SQL on different platforms: Doctrine emits `CONCAT(...)` and
     * `CAST(... AS CHAR)` on MySQL against `... || ...` and `CAST(... AS VARCHAR)` on
     * PostgreSQL. Measured on one schema across all three: 112 of 114 findings matched
     * byte for byte and two differed in exactly that way.
     *
     * Normalising the dialect out of the fingerprint is the real fix and is not cheap.
     * Saying which platform the file came from is: a project moving from one database to
     * another sees "these look new" explained instead of guessed.
     */
    public function platform(): string
    {
        return $this->platform;
    }

    /**
     * The `format-version` of a file this release cannot read, as written in it; null
     * when the file was read.
     *
     * Such a file silences nothing: its entries may mean something else under a format
     * this code does not know, and matching them anyway would silence findings by
     * accident. That makes every known finding look new, which is exactly the failure
     * that must not arrive unexplained — so the caller says why.
     */
    public function unreadableFormat(): ?string
    {
        return $this->unreadableFormat;
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

        $format = \is_array($decoded) && \array_key_exists('format-version', $decoded) ? $decoded['format-version'] : 1;

        if (!\is_int($format) || $format < 1 || $format > self::FORMAT_VERSION) {
            // a value that came out of `json_decode()` always encodes back; `?:` would not
            // do as the fallback, since `0` encodes to "0" and reads as false
            return new self([], $basePath, unreadableFormat: (string) json_encode($format));
        }

        $platform = \is_array($decoded) ? ($decoded['platform'] ?? null) : null;
        $raw = \is_array($decoded) ? ($decoded['findings'] ?? null) : null;
        $entries = [];

        foreach (\is_array($raw) ? $raw : [] as $signature => $entry) {
            if (\is_string($signature) && \is_array($entry)) {
                /* @var array<string, mixed> $entry */
                $entries[$signature] = $entry;
            }
        }

        return new self($entries, $basePath, \is_string($platform) ? $platform : '');
    }

    public function contains(Finding $finding): bool
    {
        if ('' === $finding->signature) {
            return false;
        }

        $key = $this->key($finding);

        if (!isset($this->entries[$key])) {
            return false;
        }

        $this->matched[$key] = true;

        return true;
    }

    /**
     * Entries that silenced nothing during this run.
     *
     * Deliberately not called "obsolete": after `--filter`, or a run that excluded a
     * group, an entry going unmatched only means its test did not execute. The summary
     * says which of the two it is looking at, rather than the caller guessing.
     *
     * @return list<string>
     */
    public function unmatched(): array
    {
        $unmatched = [];

        foreach (array_keys($this->entries) as $key) {
            if (!isset($this->matched[$key])) {
                $unmatched[] = $key;
            }
        }

        return $unmatched;
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

    private function relative(string $value): string
    {
        return Finding::relativeTo($value, $this->basePath);
    }

    public function count(): int
    {
        return \count($this->entries);
    }

    /**
     * @param string $platform the platform this run collected on, stamped so that a later
     *                         run on another one can say why known findings look new
     */
    public function save(string $path, string $platform = ''): bool
    {
        ksort($this->entries);

        $payload = [
            'format-version' => self::FORMAT_VERSION,
            'generated-at' => date('c'),
            'platform' => $platform,
            'comment' => 'query-guard baseline: the findings listed here do not fail the run. '
                .'Commit this file; to regenerate it, run with QUERY_GUARD_GENERATE_BASELINE=1.',
            'findings' => $this->entries,
        ];

        $json = json_encode($payload, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        if (false === $json) {
            return false;
        }

        $directory = \dirname($path);

        // created if missing, and the verdict asked again after `mkdir()` for the reason
        // spelled out in `JsonReporter::directoryReady()`: parallel workers race over the
        // same directory, and the loser of that race gets `false` from a call that did
        // exactly what was wanted
        if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
            return false;
        }

        return false !== file_put_contents($path, $json.\PHP_EOL);
    }
}
