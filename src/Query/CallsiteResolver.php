<?php

declare(strict_types=1);

namespace QueryGuard\Query;

/**
 * Finds the first application frame in a stack.
 *
 * Frames are judged by their `file` field — which is the file of the CALLER, not of the
 * callee. Judging by class would be wrong: the frame for `QueryCollector::record()`
 * carries our class but the application file we are looking for.
 *
 * The package's own files are excluded wholesale by the path to `src/`, not by listing
 * file names. A competing tool built that list by enumeration, forgot one of its own
 * classes, and every callsite it reports now points inside the package itself. Relying
 * on the substring `vendor/` is no better — a package may well be installed by path.
 *
 * The verdict per file is memoised. Resolution happens on every recorded query now that
 * the raw stack is no longer kept (see `QueryEvent`), and a stack is mostly the same few
 * dozen framework files over and over: seven regular expressions per frame turn into one
 * hash lookup after the first sighting. The cache is bounded by the number of distinct
 * files in the project.
 */
final class CallsiteResolver
{
    /** @var array<string, bool> */
    private array $verdicts = [];

    /**
     * @param list<string> $skipPatterns regular expressions matched against the file path
     */
    public function __construct(private readonly array $skipPatterns)
    {
    }

    public static function default(): self
    {
        return new self([
            self::ownSourcePattern(),
            '#/vendor/phpunit/#',
            '#/vendor/doctrine/#',
            '#/vendor/symfony/#',
            '#/vendor/laravel/#',
            '#/vendor/illuminate/#',
            '#/vendor/composer/#',
        ]);
    }

    /**
     * @param list<string> $extraPatterns
     */
    public function withPatterns(array $extraPatterns): self
    {
        return new self([...$this->skipPatterns, ...$extraPatterns]);
    }

    /**
     * @param list<array<string, mixed>> $stack the result of debug_backtrace()
     */
    public function resolve(array $stack): ?Callsite
    {
        foreach ($stack as $frame) {
            $file = $frame['file'] ?? null;

            if (!is_string($file) || '' === $file) {
                continue;
            }

            if ($this->isSkipped($file)) {
                continue;
            }

            $line = $frame['line'] ?? 0;

            return new Callsite($file, is_int($line) ? $line : 0, self::describe($frame));
        }

        return null;
    }

    private function isSkipped(string $file): bool
    {
        return $this->verdicts[$file] ??= $this->matchesSkipPattern($file);
    }

    private function matchesSkipPattern(string $file): bool
    {
        $normalized = str_replace('\\', '/', $file);

        foreach ($this->skipPatterns as $pattern) {
            if (1 === preg_match($pattern, $normalized)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $frame
     */
    private static function describe(array $frame): ?string
    {
        $function = $frame['function'] ?? null;

        if (!is_string($function)) {
            return null;
        }

        $class = $frame['class'] ?? null;

        return is_string($class) ? $class.'::'.$function : $function;
    }

    private static function ownSourcePattern(): string
    {
        $ownSource = str_replace('\\', '/', \dirname(__DIR__));

        return '#^'.preg_quote($ownSource, '#').'/#';
    }
}
