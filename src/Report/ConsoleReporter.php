<?php

declare(strict_types=1);

namespace QueryGuard\Report;

use QueryGuard\Finding\Finding;
use QueryGuard\Finding\Severity;
use QueryGuard\Mode;

/**
 * The summary printed at the end of a run.
 *
 * The stream is injected rather than opened here: otherwise there would be no way to
 * test the output. The trick is borrowed from `ergebnis/phpunit-slow-test-detector`.
 */
final class ConsoleReporter implements Reporter
{
    /**
     * @param resource $stream
     * @param Severity $failOn the `strict` threshold, so the summary can say whether the
     *                         run is actually going to fail rather than assuming it will
     */
    public function __construct(
        private $stream,
        private readonly string $basePath = '',
        private readonly Severity $failOn = Severity::Warning,
    ) {
    }

    public function report(Report $report, Mode $mode): void
    {
        $lines = ['', 'query-guard'];

        $lines[] = sprintf(
            '  tests traced: %d, queries: %d (in setUp: %d)',
            $report->tests(),
            $report->queries(),
            $report->fixtureQueries(),
        );

        if ($report->suppressed() > 0) {
            $lines[] = sprintf('  silenced by baseline: %d', $report->suppressed());
        }

        foreach ($report->notices() as $notice) {
            $lines[] = '';

            foreach (explode("\n", $notice) as $index => $noticeLine) {
                $lines[] = (0 === $index ? '  ! ' : '    ').$noticeLine;
            }
        }

        if (!$report->hasFindings()) {
            $lines[] = '';
            $lines[] = '  no findings';
        } else {
            $lines[] = '';
            $lines[] = sprintf('  findings: %d', \count($report->findings()));

            foreach (self::sorted($report->findings()) as $finding) {
                $lines[] = '';
                $lines[] = sprintf('  * [%s] %s — %s', $finding->severity->value, $finding->rule, $finding->test->label);
                $lines[] = sprintf('    %s', $finding->message);

                if (null !== $finding->callsite) {
                    $lines[] = sprintf('    %s:%d', $this->shorten($finding->callsite->file), $finding->callsite->line);
                }
            }

            if (Mode::Strict === $mode) {
                $lines[] = '';
                $lines[] = $report->hasFindingsAtLeast($this->failOn)
                    ? '  strict mode: the run is marked as failed'
                    : sprintf(
                        '  strict mode: nothing at or above "%s", so the run stays green',
                        $this->failOn->value,
                    );
            }
        }

        $lines[] = '';

        fwrite($this->stream, implode(\PHP_EOL, $lines).\PHP_EOL);
    }

    /**
     * What the tool is sure about comes first.
     *
     * Findings carry two different weights: `error` for lazy loading with the
     * association named, `warning` for a guess based on the query's shape. Mixing them
     * would drown the certain in the probable.
     *
     * @param list<Finding> $findings
     *
     * @return list<Finding>
     */
    private static function sorted(array $findings): array
    {
        usort(
            $findings,
            static fn (Finding $a, Finding $b): int => $b->severity->rank() <=> $a->severity->rank(),
        );

        return $findings;
    }

    private function shorten(string $file): string
    {
        return Finding::relativeTo($file, $this->basePath);
    }
}
