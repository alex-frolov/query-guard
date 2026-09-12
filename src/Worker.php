<?php

declare(strict_types=1);

namespace QueryGuard;

/**
 * The ParaTest worker this process is — if it is one.
 *
 * Under a parallel runner (`paratest`, or `pest --parallel`, which is ParaTest) every
 * worker is a separate process with an extension of its own: its own counters, its own
 * summary, its own trace boundaries. All of that is correct, because a trace never spans
 * workers. What is not correct is the one thing they share — the path from `report-json`.
 * Twelve processes write the same file and the last one to close wins. Measured on a real
 * Laravel suite: one selection is 34 tests sequentially and 7 in the file after
 * `--parallel`, with nothing anywhere saying that 79% of the run had been overwritten.
 *
 * ParaTest hands every worker a token through the environment, and projects already lean
 * on it to keep their databases apart (DoctrineBundle's `dbname_suffix`, for one). The same token keeps the
 * reports apart: `%token%` inside a configured path is replaced with it.
 *
 * `TEST_TOKEN` is a small number reused as workers come and go, `UNIQUE_TEST_TOKEN` is
 * unique for the whole run. The short one is preferred on purpose — a report is written
 * once, at the end of a worker's life, when the token it started with is still its own,
 * and `var/query-guard-3.json` is a name a person can read in a CI log.
 *
 * @internal
 */
final readonly class Worker
{
    public const PLACEHOLDER = '%token%';

    private const TOKEN_ENV = ['TEST_TOKEN', 'UNIQUE_TEST_TOKEN'];

    private function __construct(public string $token)
    {
    }

    public static function fromEnvironment(): self
    {
        foreach (self::TOKEN_ENV as $name) {
            $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

            if (is_scalar($value) && '' !== trim((string) $value)) {
                return new self(trim((string) $value));
            }
        }

        return new self('');
    }

    public function isWorker(): bool
    {
        return '' !== $this->token;
    }

    /**
     * The configured path with `%token%` resolved.
     *
     * Outside a parallel run the placeholder disappears together with the separator in
     * front of it, so that one spelling works in both: `var/query-guard-%token%.json` is
     * `var/query-guard-3.json` in a worker and `var/query-guard.json` in a plain run,
     * rather than the `var/query-guard-.json` that a bare substitution would leave behind.
     */
    public function inPath(string $path): string
    {
        if ('' === $this->token) {
            return (string) preg_replace('/[-_.]?'.preg_quote(self::PLACEHOLDER, '/').'/', '', $path);
        }

        return str_replace(self::PLACEHOLDER, $this->token, $path);
    }
}
