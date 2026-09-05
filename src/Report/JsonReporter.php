<?php

declare(strict_types=1);

namespace QueryGuard\Report;

use QueryGuard\Finding\Finding;
use QueryGuard\Finding\Severity;
use QueryGuard\Mode;

/**
 * The same run, written to a file as JSON.
 *
 * The console summary is for a person reading a terminal. Everything else — a CI job
 * deciding whether to comment on a pull request, a dashboard tracking whether the number
 * of findings is going up, a script turning findings into annotations — had no option but
 * to parse that summary. The README's own recipes did exactly that
 * (`grep 'against a budget'`), which is a fair description of the gap rather than a
 * defence of it: the text is written to be read, and it will be reworded.
 *
 * The console reporter stays the default and is never replaced by this one. A run that
 * writes a file and prints nothing is a run whose findings nobody sees.
 *
 * Paths are relative to the working directory, for the same reason the baseline's are:
 * the file is meant to travel to CI, and an absolute path from a developer's machine
 * means nothing there.
 */
final class JsonReporter implements Reporter
{
    public function __construct(
        private readonly string $path,
        private readonly string $basePath = '',
        private readonly Severity $failOn = Severity::Warning,
    ) {
    }

    public function report(Report $report, Mode $mode): void
    {
        $payload = [
            'generated-at' => date('c'),
            'mode' => $mode->value,
            'fail-on' => $this->failOn->value,
            'failing' => Mode::Strict === $mode && $report->hasFindingsAtLeast($this->failOn),
            'summary' => [
                'tests' => $report->tests(),
                'queries' => $report->queries(),
                'fixture-queries' => $report->fixtureQueries(),
                'suppressed' => $report->suppressed(),
                'findings' => \count($report->findings()),
            ],
            'notices' => $report->notices(),
            'findings' => array_map($this->finding(...), $report->findings()),
        ];

        $json = json_encode($payload, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        // a report that could not be written has to be said out loud, and this runs
        // before the console reporter precisely so that it can be
        if (false === $json || !is_dir(\dirname($this->path)) || false === file_put_contents($this->path, $json.\PHP_EOL)) {
            $report->addNotice(sprintf('could not write the JSON report to %s.', $this->path));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function finding(Finding $finding): array
    {
        return [
            'rule' => $finding->rule,
            'severity' => $finding->severity->value,
            'test' => $finding->test->label,
            'class' => $finding->test->className,
            'method' => $finding->test->methodName,
            'message' => $finding->message,
            'file' => null === $finding->callsite ? null : $this->relative($finding->callsite->file),
            'line' => $finding->callsite?->line,
            'count' => $finding->count,
            'signature' => $this->relative($finding->signature),
        ];
    }

    private function relative(string $value): string
    {
        return Finding::relativeTo($value, $this->basePath);
    }
}
