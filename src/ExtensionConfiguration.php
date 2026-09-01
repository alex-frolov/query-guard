<?php

declare(strict_types=1);

namespace QueryGuard;

use PHPUnit\Runner\Extension\ParameterCollection;
use QueryGuard\Finding\Severity;
use QueryGuard\Rule\DuplicateQueryRule;
use QueryGuard\Rule\NPlusOneRule;
use QueryGuard\Rule\PlanRule;
use QueryGuard\Rule\QueryInLoopRule;

/**
 * The parameters from `<bootstrap class="QueryGuard\Extension">` in phpunit.xml.
 *
 * Rules liable to be noisy on any project (`query-count`, `no-limit`, `select-star`)
 * stay silent by default: switching them on is the project owner's deliberate choice.
 *
 * `fail-on` decides what `mode="strict"` is willing to fail a run over, and defaults to
 * `warning` — so an `info` finding is printed but costs nothing. Severity is a scale of
 * certainty, and the gate has to read it; otherwise the summary says "this one is only a
 * guess" and the exit code disagrees.
 *
 * Tier 2 (`tier2`) is off for the same reason, only a stronger one: it needs a database
 * with real volume and statistics. On a three-row test database the plan rules are not
 * merely useless but harmful — the optimiser's own estimate lies there.
 *
 * **A value that could not be understood produces a warning, never a silent default.**
 * The rules themselves say out loud when they cannot judge; the configuration owes the
 * same honesty. `mode="strickt"` used to fall back to `report` without a word, and a
 * suite that nobody was failing looked exactly like a suite with nothing to report.
 *
 * A misspelled parameter *name* still cannot be caught: `ParameterCollection` answers
 * `has()` and `get()` and offers no way to enumerate what was actually written.
 */
final readonly class ExtensionConfiguration
{
    public const GENERATE_BASELINE_ENV = 'QUERY_GUARD_GENERATE_BASELINE';

    /**
     * @param list<string> $largeTables
     * @param list<string> $warnings    parameters that could not be understood; the
     *                                  extension puts these into the summary
     */
    public function __construct(
        public Mode $mode = Mode::Report,
        public ?int $maxQueries = null,
        public int $nPlusOneThreshold = NPlusOneRule::DEFAULT_THRESHOLD,
        public int $duplicateThreshold = DuplicateQueryRule::DEFAULT_THRESHOLD,
        public int $queryInLoopThreshold = QueryInLoopRule::DEFAULT_THRESHOLD,
        public bool $selectStar = false,
        public array $largeTables = [],
        public string $baselinePath = '',
        public bool $generateBaseline = false,
        public bool $tier2 = false,
        public int $minRows = PlanRule::DEFAULT_MIN_ROWS,
        public Severity $failOn = Severity::Warning,
        public array $warnings = [],
    ) {
    }

    public static function fromParameters(ParameterCollection $parameters): self
    {
        $warnings = [];

        return new self(
            mode: self::mode($parameters, $warnings),
            maxQueries: self::positiveInt($parameters, 'max-queries', null, 1, $warnings),
            nPlusOneThreshold: self::positiveInt($parameters, 'n-plus-one-threshold', NPlusOneRule::DEFAULT_THRESHOLD, 2, $warnings) ?? NPlusOneRule::DEFAULT_THRESHOLD,
            duplicateThreshold: self::positiveInt($parameters, 'duplicate-threshold', DuplicateQueryRule::DEFAULT_THRESHOLD, 2, $warnings) ?? DuplicateQueryRule::DEFAULT_THRESHOLD,
            queryInLoopThreshold: self::positiveInt($parameters, 'query-in-loop-threshold', QueryInLoopRule::DEFAULT_THRESHOLD, 2, $warnings) ?? QueryInLoopRule::DEFAULT_THRESHOLD,
            selectStar: self::bool($parameters, 'select-star', $warnings),
            largeTables: self::list($parameters, 'large-tables'),
            baselinePath: $parameters->has('baseline') ? trim($parameters->get('baseline')) : '',
            generateBaseline: self::generateBaselineRequested(),
            tier2: self::bool($parameters, 'tier2', $warnings),
            minRows: self::positiveInt($parameters, 'min-rows', PlanRule::DEFAULT_MIN_ROWS, 1, $warnings) ?? PlanRule::DEFAULT_MIN_ROWS,
            failOn: self::failOn($parameters, $warnings),
            warnings: $warnings,
        );
    }

    /**
     * Regenerating the baseline is requested with an environment variable rather than a
     * command-line flag: PHPUnit's event system gives an extension no way to add its own
     * option to `phpunit`.
     */
    public static function generateBaselineRequested(): bool
    {
        $value = $_ENV[self::GENERATE_BASELINE_ENV] ?? $_SERVER[self::GENERATE_BASELINE_ENV] ?? getenv(self::GENERATE_BASELINE_ENV);

        if (!\is_string($value)) {
            return false;
        }

        return \in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @param list<string> $warnings
     */
    private static function mode(ParameterCollection $parameters, array &$warnings): Mode
    {
        if (!$parameters->has('mode')) {
            return Mode::Report;
        }

        $raw = trim($parameters->get('mode'));
        $mode = Mode::tryFrom(strtolower($raw));

        if (null === $mode) {
            $warnings[] = sprintf(
                'mode="%s" is not a mode — the run went ahead in "report", where nothing fails. Expected: report, strict.',
                $raw,
            );

            return Mode::Report;
        }

        return $mode;
    }

    /**
     * Which severities `strict` mode is willing to fail a run over.
     *
     * The default is `warning`, which leaves `info` out. That is not timidity: the only
     * `info` rule is `select-star`, and `select *` is Eloquent's default mode — failing
     * on it would turn a whole Laravel suite red the moment someone enables the rule.
     * Set `fail-on="info"` to hold the suite to everything, `fail-on="error"` to fail
     * only on what the adapter proved rather than guessed.
     *
     * @param list<string> $warnings
     */
    private static function failOn(ParameterCollection $parameters, array &$warnings): Severity
    {
        if (!$parameters->has('fail-on')) {
            return Severity::Warning;
        }

        $raw = trim($parameters->get('fail-on'));
        $severity = Severity::tryFrom(strtolower($raw));

        if (null === $severity) {
            $warnings[] = sprintf(
                'fail-on="%s" is not a severity — the default "warning" was used instead. Expected: error, warning, info.',
                $raw,
            );

            return Severity::Warning;
        }

        return $severity;
    }

    /**
     * @param list<string> $warnings
     */
    private static function positiveInt(ParameterCollection $parameters, string $name, ?int $default, int $minimum, array &$warnings): ?int
    {
        if (!$parameters->has($name)) {
            return $default;
        }

        $value = trim($parameters->get($name));

        if ('' === $value || !ctype_digit($value) || (int) $value < $minimum) {
            $warnings[] = sprintf(
                '%s="%s" is not a whole number of %d or more — %s',
                $name,
                $value,
                $minimum,
                null === $default
                    ? 'the rule stays silent, as if the parameter had not been set at all.'
                    : sprintf('the default %d was used instead.', $default),
            );

            return $default;
        }

        return (int) $value;
    }

    /**
     * @param list<string> $warnings
     */
    private static function bool(ParameterCollection $parameters, string $name, array &$warnings): bool
    {
        if (!$parameters->has($name)) {
            return false;
        }

        $value = strtolower(trim($parameters->get($name)));

        if (\in_array($value, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }

        if (!\in_array($value, ['0', 'false', 'no', 'off', ''], true)) {
            $warnings[] = sprintf(
                '%s="%s" is not a yes/no value — read as "off". Expected: true, false, 1, 0, yes, no, on, off.',
                $name,
                trim($parameters->get($name)),
            );
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function list(ParameterCollection $parameters, string $name): array
    {
        if (!$parameters->has($name)) {
            return [];
        }

        $items = array_map(trim(...), explode(',', $parameters->get($name)));

        return array_values(array_filter($items, static fn (string $item): bool => '' !== $item));
    }
}
