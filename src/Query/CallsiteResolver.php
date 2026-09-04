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
 * Projects add their own frameworks to the skip list through the `skip-paths`
 * parameter, and it is held statically for the same reason `QueryGuard` is: a DBAL
 * middleware is built by the application's container, which has no way to reach the
 * PHPUnit extension and be handed a configured resolver. The extension writes the list
 * during `bootstrap()`, long before any connection exists, and every `default()` built
 * afterwards carries it.
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
     * Extra path fragments configured by the project — see the class docblock.
     *
     * @var list<string>
     */
    private static array $configured = [];

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
            ...self::$configured,
        ]);
    }

    /**
     * Path fragments the project wants stepped over, as written in `skip-paths`.
     *
     * A fragment, not a regular expression: `vendor/api-platform/` is what a developer
     * knows, and asking for `#/vendor/api-platform/#` in an XML attribute is asking for a
     * silently broken pattern. Whatever is given is quoted and matched anywhere in the
     * path, with separators normalised to `/` so a Windows checkout answers the same.
     *
     * Callers that build a resolver of their own — `Recorder` and `EloquentAdapter` —
     * go through `default()`, so setting this once covers every adapter.
     *
     * @param list<string> $fragments
     */
    public static function configureSkipPaths(array $fragments): void
    {
        $patterns = [];

        foreach ($fragments as $fragment) {
            $fragment = trim(str_replace('\\', '/', $fragment), '/');

            if ('' !== $fragment) {
                $patterns[] = '#/'.preg_quote($fragment, '#').'/#';
            }
        }

        self::$configured = $patterns;
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
