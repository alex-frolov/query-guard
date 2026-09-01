<?php

declare(strict_types=1);

namespace QueryGuard\Platform;

/**
 * A normalised query plan. Rules only ever see this, never the raw `EXPLAIN` output —
 * otherwise every rule would have to be written once per platform.
 */
final readonly class Plan
{
    /**
     * @param list<PlanNode> $nodes
     * @param string         $connection which connection answered. A rule needs it to ask
     *                                   further questions — table sizes, platform
     *                                   capabilities — of the same database that produced
     *                                   this plan rather than of whichever connected last
     */
    public function __construct(
        public string $platform,
        public array $nodes,
        public ?float $cost = null,
        public string $connection = '',
    ) {
    }

    public static function empty(string $platform): self
    {
        return new self($platform, []);
    }

    public function onConnection(string $connection): self
    {
        return new self($this->platform, $this->nodes, $this->cost, $connection);
    }

    public function isEmpty(): bool
    {
        return [] === $this->nodes;
    }

    /**
     * @return int|null null when no node reported an estimate
     */
    public function estimatedRows(): ?int
    {
        $total = null;

        foreach ($this->nodes as $node) {
            if (null !== $node->estimatedRows) {
                $total = ($total ?? 0) + $node->estimatedRows;
            }
        }

        return $total;
    }
}
