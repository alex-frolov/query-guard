<?php

declare(strict_types=1);

namespace QueryGuard;

use PHPUnit\Runner\Extension\ParameterCollection;
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
 * Tier 2 (`tier2`) is off for the same reason, only a stronger one: it needs a database
 * with real volume and statistics. On a three-row test database the plan rules are not
 * merely useless but harmful — the optimiser's own estimate lies there.
 */
final readonly class ExtensionConfiguration
{
    public const GENERATE_BASELINE_ENV = 'QUERY_GUARD_GENERATE_BASELINE';

    /**
     * @param list<string> $largeTables
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
    ) {
    }

    public static function fromParameters(ParameterCollection $parameters): self
    {
        return new self(
            mode: $parameters->has('mode') ? Mode::fromString($parameters->get('mode')) : Mode::Report,
            maxQueries: self::positiveInt($parameters, 'max-queries', null),
            nPlusOneThreshold: self::positiveInt($parameters, 'n-plus-one-threshold', NPlusOneRule::DEFAULT_THRESHOLD, 2) ?? NPlusOneRule::DEFAULT_THRESHOLD,
            duplicateThreshold: self::positiveInt($parameters, 'duplicate-threshold', DuplicateQueryRule::DEFAULT_THRESHOLD, 2) ?? DuplicateQueryRule::DEFAULT_THRESHOLD,
            queryInLoopThreshold: self::positiveInt($parameters, 'query-in-loop-threshold', QueryInLoopRule::DEFAULT_THRESHOLD, 2) ?? QueryInLoopRule::DEFAULT_THRESHOLD,
            selectStar: self::bool($parameters, 'select-star'),
            largeTables: self::list($parameters, 'large-tables'),
            baselinePath: $parameters->has('baseline') ? trim($parameters->get('baseline')) : '',
            generateBaseline: self::generateBaselineRequested(),
            tier2: self::bool($parameters, 'tier2'),
            minRows: self::positiveInt($parameters, 'min-rows', PlanRule::DEFAULT_MIN_ROWS) ?? PlanRule::DEFAULT_MIN_ROWS,
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

    private static function positiveInt(ParameterCollection $parameters, string $name, ?int $default, int $minimum = 1): ?int
    {
        if (!$parameters->has($name)) {
            return $default;
        }

        $value = trim($parameters->get($name));

        if ('' === $value || !ctype_digit($value) || (int) $value < $minimum) {
            return $default;
        }

        return (int) $value;
    }

    private static function bool(ParameterCollection $parameters, string $name): bool
    {
        if (!$parameters->has($name)) {
            return false;
        }

        return \in_array(strtolower(trim($parameters->get($name))), ['1', 'true', 'yes', 'on'], true);
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
