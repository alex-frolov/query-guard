<?php

declare(strict_types=1);

namespace QueryGuard\Platform;

/**
 * A normalised query plan. Rules only ever see this, never the raw `EXPLAIN` output —
 * otherwise every rule would have to be written once per platform.
 *
 * @internal
 */
final readonly class Plan
{
    /**
     * @param list<PlanNode> $nodes
     * @param string         $connection     which connection answered. A rule needs it to
     *                                       ask further questions — table sizes, platform
     *                                       capabilities — of the same database that
     *                                       produced this plan rather than of whichever
     *                                       connected last
     * @param string|null    $nothingToJudge why a plan with no nodes is still a valid
     *                                       answer: the optimiser decided without reading
     *                                       a table (`no matching row in const table`,
     *                                       `No tables used`, `WHERE 1 = 0`). Null on a
     *                                       plan that has nodes, and on one that could not
     *                                       be read at all
     */
    public function __construct(
        public string $platform,
        public array $nodes,
        public string $connection = '',
        public ?string $nothingToJudge = null,
    ) {
    }

    /**
     * A plan that could not be read.
     */
    public static function empty(string $platform): self
    {
        return new self($platform, []);
    }

    /**
     * A plan that was read and reads no table — there is nothing a plan rule could judge.
     *
     * Kept apart from `empty()` because the two used to be one: on a MySQL project a
     * quarter of all plans are lookups by a unique key that found no row, and counting
     * them as failures made tier 2 look broken when it was not.
     */
    public static function readingNoTable(string $platform, string $reason): self
    {
        return new self($platform, [], '', $reason);
    }

    public function onConnection(string $connection): self
    {
        return new self($this->platform, $this->nodes, $connection, $this->nothingToJudge);
    }

    public function isEmpty(): bool
    {
        return [] === $this->nodes;
    }
}
