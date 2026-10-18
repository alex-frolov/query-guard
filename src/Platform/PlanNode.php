<?php

declare(strict_types=1);

namespace QueryGuard\Platform;

/**
 * A single plan node, in terms shared by every platform.
 *
 * @internal
 */
final readonly class PlanNode
{
    /**
     * @param list<string>|null $possibleIndexes null means the platform does not report
     *                                           which indexes could have applied;
     *                                           an empty list means none could
     * @param bool              $derived         the rows come from a subquery materialised
     *                                           on the fly (`JOIN (SELECT ...) x`). Such a
     *                                           table has no index by definition, so a
     *                                           full scan of it says nothing about the
     *                                           schema — the subquery's own tables do
     * @param bool|null         $hasCondition    whether rows of this table are filtered
     *                                           at all — by a WHERE or a join condition.
     *                                           Null when that cannot be told: the driver
     *                                           does not say, or the table is one of a
     *                                           join, where the condition may sit on the
     *                                           other side. Without a condition there is
     *                                           nothing an index could narrow: `findAll()`
     *                                           reads every row however it is indexed
     */
    public function __construct(
        public string $table,
        public ScanType $scanType,
        public ?string $usedIndex = null,
        public ?array $possibleIndexes = null,
        public ?int $estimatedRows = null,
        public bool $filesort = false,
        public bool $temporaryTable = false,
        public bool $derived = false,
        public ?bool $hasCondition = null,
    ) {
    }

    public function usesIndex(): bool
    {
        return null !== $this->usedIndex && '' !== $this->usedIndex;
    }

    /**
     * No suitable index exists in the schema at all — a fact about the schema rather
     * than about the data, so it holds on an empty table too. That is why the
     * `no-possible-index` rule belongs to tier 1 in spirit even though it technically
     * needs EXPLAIN.
     *
     * `null` means "the platform does not answer this question", and turning that
     * quietly into "there are no indexes" is not allowed.
     */
    public function hasNoPossibleIndex(): ?bool
    {
        if (null === $this->possibleIndexes) {
            return null;
        }

        // an empty candidate list means nothing on its own: for
        // `SELECT indexed_col FROM big ORDER BY indexed_col` MySQL reports no
        // `possible_keys` yet still uses the index (observed on the stand).
        // "No index" means a scan with no key AND no candidates.
        return [] === $this->possibleIndexes && !$this->usesIndex();
    }
}
