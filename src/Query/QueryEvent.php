<?php

declare(strict_types=1);

namespace QueryGuard\Query;

/**
 * One database query, as the ORM adapter saw it.
 *
 * The core knows neither Doctrine nor Eloquent: an adapter converts its own event into
 * this shape and hands it to the collector.
 *
 * **The callsite is resolved by the adapter, not stored as a stack.** It used to be the
 * other way round — the raw `debug_backtrace` was kept and resolved lazily, on the
 * grounds that only findings need it. That was measured and it does not hold: 200 frames
 * per query, all of them distinct arrays, cost ~106 MB per 1000 queries, and a trace
 * lives until the end of its test. Resolving once at record time — where the stack is
 * already in hand — costs one walk and stores one small object.
 *
 * `stack` remains for anyone feeding the collector by hand (see the README): pass a
 * stack and it is resolved lazily, exactly as before.
 */
final class QueryEvent
{
    private ?Fingerprint $fingerprint = null;

    /**
     * Memoised per resolver: walking the stack costs, and it is asked for more than once.
     *
     * Keyed by the resolver object itself rather than by `spl_object_id()`. PHP hands a
     * freed object's id to the next one allocated, so a short-lived resolver — and
     * `CallsiteResolver::withPatterns()` exists to make those — would inherit the
     * verdict of an unrelated one and report a callsite it had been told to skip.
     *
     * Built lazily: a trace holds every event of a test alive, and in the common path
     * the adapter has already resolved the callsite, so the map is never needed at all.
     *
     * @var \WeakMap<CallsiteResolver, array{Callsite|null}>|null
     */
    private ?\WeakMap $callsites = null;

    private ?string $shape = null;

    /**
     * @param array<array-key, mixed>    $params
     * @param list<array<string, mixed>> $stack       the result of debug_backtrace(); leave empty when $callsite is given
     * @param array<string, mixed>       $annotations enrichment from the adapter (entity, association, proxy flag)
     * @param Callsite|null              $callsite    already resolved by the adapter — then $stack is not needed
     */
    public function __construct(
        public readonly string $sql,
        public readonly array $params = [],
        public readonly ?float $durationMs = null,
        public readonly string $connection = 'default',
        public readonly array $stack = [],
        public readonly array $annotations = [],
        private readonly ?Callsite $callsite = null,
    ) {
    }

    public function fingerprint(): Fingerprint
    {
        return $this->fingerprint ??= Fingerprint::of($this->sql);
    }

    public function callsite(CallsiteResolver $resolver): ?Callsite
    {
        if (null !== $this->callsite) {
            return $this->callsite;
        }

        $this->callsites ??= new \WeakMap();

        if (!$this->callsites->offsetExists($resolver)) {
            // wrapped in an array so that a resolved "no callsite" is remembered as an
            // answer rather than looking like a missing entry on the next call
            $this->callsites->offsetSet($resolver, [$resolver->resolve($this->stack)]);
        }

        return $this->callsites->offsetGet($resolver)[0];
    }

    /**
     * The exact form of the query, values included.
     *
     * This is what separates N+1 from a duplicate: in N+1 the values differ by
     * definition (different rows fetched by different keys), whereas in a duplicate
     * everything matches. Missing that distinction is precisely where the closest
     * competitor fails — it treats a match of SQL AND bound values as a duplicate,
     * and therefore never sees N+1.
     *
     * `serialize()` throws on a value it cannot represent — a closure, most plausibly
     * out of an Eloquent binding. This runs inside `Test\Finished`, so an exception here
     * would escape into PHPUnit's event dispatcher: a tool that watches for problems
     * must not be able to break the suite it is watching. The fallback separates
     * queries by parameter count only, which under-reports duplicates rather than
     * inventing them.
     *
     * The SQL goes through `SqlText::withoutComments()` first: a per-request comment
     * from tracing middleware or sqlcommenter must not turn two identical queries into
     * two different shapes, or `duplicate-query` stops seeing them — and `n-plus-one`
     * starts misreading the same duplicates as lazy loading instead.
     */
    public function shape(): string
    {
        if (null !== $this->shape) {
            return $this->shape;
        }

        $sql = SqlText::withoutComments($this->sql);

        try {
            return $this->shape = $sql.'|'.serialize($this->params);
        } catch (\Throwable) {
            return $this->shape = $sql.'|?'.\count($this->params);
        }
    }

    /**
     * Whether this is a read or a write.
     *
     * N+1 is a property of reads — "one query per row" while iterating a result set —
     * and it is cured by eager loading. Repeated INSERTs from a factory are a different
     * phenomenon with a different cure (batch insert); lumping them together guarantees
     * false positives on fixtures.
     *
     * Both comment spellings are skipped, block and line alike. A statement introduced
     * by a `--` line — sqlcommenter-style tracing emits them — read as a write, and
     * a read misfiled that way disappears silently: out of `n-plus-one`, out of
     * `duplicate-query`, and out of tier 2, which never even asks for its plan.
     */
    public function isSelect(): bool
    {
        return 1 === preg_match('#^\s*(?:(?:/\*.*?\*/|--[^\n]*\n)\s*)*(SELECT|WITH)\b#is', $this->sql);
    }

    public function annotation(string $name): mixed
    {
        return $this->annotations[$name] ?? null;
    }
}
