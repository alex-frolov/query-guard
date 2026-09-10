<?php

declare(strict_types=1);

namespace QueryGuard\Platform;

/**
 * A single plan node, in terms shared by every platform.
 */
final readonly class PlanNode
{
    /**
     * @param list<string>|null $possibleIndexes null means the platform does not report
     *                                           which indexes could have applied;
     *                                           an empty list means none could
     */
    public function __construct(
        public string $table,
        public ScanType $scanType,
        public ?string $usedIndex = null,
        public ?array $possibleIndexes = null,
        public ?int $estimatedRows = null,
        public bool $filesort = false,
        public bool $temporaryTable = false,
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
