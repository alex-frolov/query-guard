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
        public string $connection = '',
    ) {
    }

    public static function empty(string $platform): self
    {
        return new self($platform, []);
    }

    public function onConnection(string $connection): self
    {
        return new self($this->platform, $this->nodes, $connection);
    }

    public function isEmpty(): bool
    {
        return [] === $this->nodes;
    }
}
