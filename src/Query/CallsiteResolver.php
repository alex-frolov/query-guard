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
 * Some transit frames cannot be recognised by their path, because their path is genuinely
 * the application's — see `DEFAULT_SKIP_FUNCTIONS`. Those are matched on what the frame
 * called instead, through the same mechanism and a second parameter, `skip-functions`.
 *
 * The verdict per file is memoised. Resolution happens on every recorded query now that
 * the raw stack is no longer kept (see `QueryEvent`), and a stack is mostly the same few
 * dozen framework files over and over: seven regular expressions per frame turn into one
 * hash lookup after the first sighting. The cache is bounded by the number of distinct
 * files in the project.
 */
final class CallsiteResolver
{
    /**
     * How many application frames above the callsite a finding carries by default.
     *
     * Not zero, deliberately. The projects that need the chain most are the ones where
     * every finding lands on the same shared frame — a magic accessor, a repository base
     * class — and there the report is unreadable without it. `call-chain-depth="0"`
     * turns it off.
     *
     * Four rather than three, measured rather than guessed. On a Laravel project whose
     * every repository extends one from `prettus/l5-repository`, the first frame in the
     * project's own code sits at slot 0, 1 or 2 — never deeper — because the wrapper is
     * three frames thick: `CacheableRepository::findWhere` → `BaseRepository::findWhere`
     * → `CacheManager::__call`. At three, 30% of that suite's findings spent their whole
     * budget inside the wrapper and named nobody; the fourth frame clears it and takes
     * the cohort from 99.1% to 100%, and the mean from 1.96 named callers to 2.95.
     *
     * Counting only the project's own frames and letting a wrapper pass through for free
     * was measured too, and it is worse: it needs a whitelist where the skip list is a
     * blocklist, a second parameter to bound the walk, and it yields 2.51 application
     * frames per finding against this constant's 2.91.
     */
    public const DEFAULT_CHAIN_DEPTH = 4;

    /**
     * Frames recognised by what they called rather than by where they live.
     *
     * A backtrace frame's `file` is the caller's, so a frame reads "this line called that
     * function". Laravel's middleware pipeline produces frames whose file is the
     * application's own middleware — the `return $next($request);` line — and whose
     * function is a closure declared inside `Illuminate\Pipeline\Pipeline`. The line is
     * real application code and `skip-paths` cannot exclude it without also excluding the
     * middleware's actual body, which may well be what issued the query. But as a caller
     * it says nothing: every HTTP request in the application passes through every one of
     * those lines.
     *
     * Measured on a Laravel CRM's admin screens, 136 findings: pipeline closures held 150
     * of 533 chain slots (28%), and for 33 findings (24%) they were the *entire*
     * application chain — four slots spent naming plumbing that named nobody. The top
     * "who called this" entry across the whole report was one middleware's `$next()` line
     * with 32 findings against it, which is not a place anything can be fixed.
     *
     * Matched on the class rather than the closure's own name on purpose: PHP 8.4 renamed
     * closures to carry their enclosing function, so `Illuminate\Pipeline\{closure}` is
     * not stable across versions while `Illuminate\Pipeline\Pipeline::` is. Frames for
     * the pipeline's ordinary methods carry that prefix too, but they live under
     * `vendor/laravel/` and never reach this test.
     *
     * @var list<string>
     */
    public const DEFAULT_SKIP_FUNCTIONS = [
        'Illuminate\Pipeline\Pipeline::',
    ];

    /**
     * How many distinct chains to keep before starting over — the same bound, for the
     * same reason, as `SqlText::CACHE_LIMIT`.
     */
    private const CHAIN_LIMIT = 2000;

    /**
     * One array per distinct chain, shared by every callsite that has it.
     *
     * A chain is the same string list for every query of an N+1 — on one real suite the
     * top two callsites accounted for 8 000 findings between them. Sharing turns
     * "three strings per recorded query" into "three strings per distinct call path".
     *
     * @var array<string, list<string>>
     */
    private static array $chains = [];

    /** @var array<string, bool> */
    private array $verdicts = [];

    /**
     * Extra path fragments configured by the project — see the class docblock.
     *
     * @var list<string>
     */
    private static array $configured = [];

    /**
     * Extra function fragments configured by the project — see `configureSkipFunctions()`.
     *
     * @var list<string>
     */
    private static array $configuredFunctions = [];

    /**
     * Chain depth configured by the project, held statically for the same reason as the
     * skip list above: the resolvers that matter are built where nothing can be injected.
     */
    private static int $configuredChainDepth = self::DEFAULT_CHAIN_DEPTH;

    /** @var array<string, bool> */
    private array $functionVerdicts = [];

    /**
     * @param list<string> $skipPatterns         regular expressions matched against the file path
     * @param int          $chainDepth           application frames kept above the callsite; 0 keeps none
     * @param list<string> $skipFunctionPatterns regular expressions matched against `Class::method`
     */
    public function __construct(
        private readonly array $skipPatterns,
        private readonly int $chainDepth = self::DEFAULT_CHAIN_DEPTH,
        private readonly array $skipFunctionPatterns = [],
    ) {
    }

    public static function default(): self
    {
        return new self([
            self::ownSourcePattern(),
            '#/vendor/phpunit/#',
            // the runner's own binary, which is not under `vendor/phpunit/`: without it
            // every chain that reaches the top of a test ends in `vendor/bin/phpunit include`
            '#/vendor/bin/#',
            // Pest, whose frames sit between a test body and the runner rather than above
            // the callsite. A test that queries directly has nobody above it, and without
            // this the chain filled with nine frames of `TestCaseMethodFactory` →
            // `Testable::__callClosure` → `Kernel::handle` that name no caller and cannot:
            // on one real suite that was 4.4% of findings and 46% of all wasted slots. The
            // whole package, not `pest/src/`, because `vendor/pestphp/pest/bin/pest` is
            // where the last two frames come from and `#/vendor/bin/#` does not reach it.
            '#/vendor/pestphp/pest/#',
            '#/vendor/doctrine/#',
            '#/vendor/symfony/#',
            '#/vendor/laravel/#',
            '#/vendor/illuminate/#',
            '#/vendor/composer/#',
            ...self::$configured,
        ], self::$configuredChainDepth, [
            ...self::functionPatterns(self::DEFAULT_SKIP_FUNCTIONS),
            ...self::$configuredFunctions,
        ]);
    }

    /**
     * How many application frames a finding carries above its callsite, as written in
     * `call-chain-depth`. Negative is read as none.
     */
    public static function configureChainDepth(int $depth): void
    {
        self::$configuredChainDepth = max(0, $depth);
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
        self::$configured = self::pathPatterns($fragments);
    }

    /**
     * Path fragments compiled the way this class matches them, for anything else that has
     * to answer "is this file inside one of these directories" about the same kind of
     * list written by the same hand.
     *
     * `fixture-paths` is the caller: it takes fragments in the same notation as
     * `skip-paths`, for the same reason, and a second normalisation with its own quirks
     * would be a trap — the two lists sit next to each other in the same XML.
     *
     * @param list<string> $fragments
     *
     * @return list<string>
     */
    public static function pathPatterns(array $fragments): array
    {
        $patterns = [];

        foreach ($fragments as $fragment) {
            $fragment = trim(str_replace('\\', '/', $fragment), '/');

            if ('' !== $fragment) {
                $patterns[] = '#/'.preg_quote($fragment, '#').'/#';
            }
        }

        return $patterns;
    }

    /**
     * Whether a path matches one of the patterns `pathPatterns()` built. Separators are
     * normalised here rather than at the call site so a Windows checkout answers the same.
     *
     * @param list<string> $patterns
     */
    public static function pathMatches(string $file, array $patterns): bool
    {
        $normalized = str_replace('\\', '/', $file);

        foreach ($patterns as $pattern) {
            if (1 === preg_match($pattern, $normalized)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Function fragments the project wants stepped over, as written in `skip-functions`.
     *
     * A fragment matched anywhere in `Class::method`, for the same reason `skip-paths`
     * takes a fragment: `Illuminate\Pipeline\Pipeline::` is what a developer reads off a
     * chain line, and asking for a regular expression in an XML attribute is asking for a
     * silently broken one. Added to `DEFAULT_SKIP_FUNCTIONS` rather than replacing it.
     *
     * The one thing worth knowing before reaching for this: it filters by what a frame
     * *called*, not by where it is. Naming your own method here hides every frame that
     * calls it, wherever from — which is the point for plumbing and a mistake for
     * anything else.
     *
     * @param list<string> $fragments
     */
    public static function configureSkipFunctions(array $fragments): void
    {
        self::$configuredFunctions = self::functionPatterns($fragments);
    }

    /**
     * @param list<string> $fragments
     *
     * @return list<string>
     */
    private static function functionPatterns(array $fragments): array
    {
        $patterns = [];

        foreach ($fragments as $fragment) {
            $fragment = trim($fragment);

            if ('' !== $fragment) {
                $patterns[] = '#'.preg_quote($fragment, '#').'#';
            }
        }

        return $patterns;
    }

    /**
     * @param list<string> $extraPatterns
     */
    public function withPatterns(array $extraPatterns): self
    {
        return new self([...$this->skipPatterns, ...$extraPatterns], $this->chainDepth, $this->skipFunctionPatterns);
    }

    /**
     * @param list<array<string, mixed>> $stack the result of debug_backtrace()
     */
    public function resolve(array $stack): ?Callsite
    {
        $file = null;
        $line = 0;
        $function = null;
        $chain = [];

        foreach ($stack as $frame) {
            $current = $frame['file'] ?? null;

            if (!is_string($current) || '' === $current) {
                continue;
            }

            if ($this->isSkippedPath($current)) {
                continue;
            }

            $currentFunction = self::describe($frame);

            // resolved after the path, not before: a frame the path list already rejects
            // never needs describing, and most frames in a stack are such frames
            if (null !== $currentFunction && $this->isSkippedFunction($currentFunction)) {
                continue;
            }

            $currentLine = $frame['line'] ?? 0;
            $currentLine = is_int($currentLine) ? $currentLine : 0;

            if (null === $file) {
                $file = $current;
                $line = $currentLine;
                $function = $currentFunction;

                if (0 === $this->chainDepth) {
                    // nothing above is wanted, so nothing above is walked
                    break;
                }

                continue;
            }

            $chain[] = self::format($current, $currentLine, $currentFunction);

            if (\count($chain) >= $this->chainDepth) {
                break;
            }
        }

        return null === $file ? null : new Callsite($file, $line, $function, self::intern($chain));
    }

    private function isSkippedPath(string $file): bool
    {
        return $this->verdicts[$file] ??= $this->matchesSkipPattern($file);
    }

    /**
     * Memoised on its own, next to the per-file cache and for the same reason: a stack is
     * the same few dozen `Class::method` strings over and over, and this list is empty on
     * most projects, so the common case is one hash lookup that finds `false`.
     */
    private function isSkippedFunction(string $function): bool
    {
        if ([] === $this->skipFunctionPatterns) {
            return false;
        }

        return $this->functionVerdicts[$function] ??= $this->matchesSkipFunctionPattern($function);
    }

    private function matchesSkipPattern(string $file): bool
    {
        return self::pathMatches($file, $this->skipPatterns);
    }

    private function matchesSkipFunctionPattern(string $function): bool
    {
        foreach ($this->skipFunctionPatterns as $pattern) {
            if (1 === preg_match($pattern, $function)) {
                return true;
            }
        }

        return false;
    }

    /**
     * One frame as a finding shows it: where the call was made, and what it called.
     */
    private static function format(string $file, int $line, ?string $function): string
    {
        return $file.':'.$line.(null === $function ? '' : ' '.$function);
    }

    /**
     * @param list<string> $chain
     *
     * @return list<string>
     */
    private static function intern(array $chain): array
    {
        if ([] === $chain) {
            return [];
        }

        $key = implode("\n", $chain);

        if (\array_key_exists($key, self::$chains)) {
            return self::$chains[$key];
        }

        if (\count(self::$chains) >= self::CHAIN_LIMIT) {
            self::$chains = [];
        }

        return self::$chains[$key] = $chain;
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
