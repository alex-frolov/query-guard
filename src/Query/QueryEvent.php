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

    /** @var array<int, Callsite|null> memoised per resolver: walking the stack costs, and it is asked for more than once */
    private array $callsites = [];

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

        $key = spl_object_id($resolver);

        if (!\array_key_exists($key, $this->callsites)) {
            $this->callsites[$key] = $resolver->resolve($this->stack);
        }

        return $this->callsites[$key];
    }

    /**
     * The exact form of the query, values included.
     *
     * This is what separates N+1 from a duplicate: in N+1 the values differ by
     * definition (different rows fetched by different keys), whereas in a duplicate
     * everything matches. Missing that distinction is precisely where the closest
     * competitor fails — it treats a match of SQL AND bound values as a duplicate,
     * and therefore never sees N+1.
     */
    public function shape(): string
    {
        return $this->shape ??= $this->sql.'|'.serialize($this->params);
    }

    /**
     * Whether this is a read or a write.
     *
     * N+1 is a property of reads — "one query per row" while iterating a result set —
     * and it is cured by eager loading. Repeated INSERTs from a factory are a different
     * phenomenon with a different cure (batch insert); lumping them together guarantees
     * false positives on fixtures.
     */
    public function isSelect(): bool
    {
        return 1 === preg_match('/^\s*(?:\/\*.*?\*\/\s*)*(SELECT|WITH)\b/i', $this->sql);
    }

    public function annotation(string $name): mixed
    {
        return $this->annotations[$name] ?? null;
    }
}
