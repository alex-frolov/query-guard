<?php

declare(strict_types=1);

namespace QueryGuard\Report;

use QueryGuard\Finding\Finding;
use QueryGuard\Query\Trace;

/**
 * What accumulates over a run: findings, counters and notices.
 *
 * The notices are not decoration. When an ORM or a platform is unsupported, the summary
 * has to say so out loud: silent degradation is exactly how a tool loses trust.
 */
final class Report
{
    /** @var list<Finding> */
    private array $findings = [];

    /** @var list<string> */
    private array $notices = [];

    private int $tests = 0;

    private int $queries = 0;

    private int $fixtureQueries = 0;

    private int $suppressed = 0;

    /**
     * @param list<Finding> $findings
     */
    public function addTrace(Trace $trace, array $findings): void
    {
        ++$this->tests;
        $this->queries += $trace->count();
        $this->fixtureQueries += $trace->fixtureQueryCount();

        foreach ($findings as $finding) {
            $this->findings[] = $finding;
        }
    }

    /**
     * How many findings the baseline silenced.
     *
     * Silence must not be silent about itself: the summary has to show that some
     * findings are hidden, otherwise "no findings" starts to mean two different things.
     */
    public function suppress(int $count): void
    {
        $this->suppressed += $count;
    }

    public function suppressed(): int
    {
        return $this->suppressed;
    }

    public function addNotice(string $notice): void
    {
        $this->notices[] = $notice;
    }

    /**
     * @return list<Finding>
     */
    public function findings(): array
    {
        return $this->findings;
    }

    /**
     * @return list<string>
     */
    public function notices(): array
    {
        return $this->notices;
    }

    public function tests(): int
    {
        return $this->tests;
    }

    public function queries(): int
    {
        return $this->queries;
    }

    public function fixtureQueries(): int
    {
        return $this->fixtureQueries;
    }

    public function hasFindings(): bool
    {
        return [] !== $this->findings;
    }
}
