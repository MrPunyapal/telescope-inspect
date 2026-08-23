<?php

namespace MrPunyapal\TelescopeInspect\Filters;

use MrPunyapal\TelescopeInspect\Entries\EntryType;

/**
 * Fully resolved, validated inspection filters.
 *
 * Built from raw Artisan option values; construction fails with an
 * InvalidFilter exception when a value cannot be understood or when a
 * filter can never apply to the selected entry types.
 *
 * @internal
 */
final class InspectFilters
{
    /**
     * @param  list<EntryType>  $types
     * @param  list<string>  $methods
     * @param  list<int>  $statuses
     * @param  list<string>  $failOn
     */
    public function __construct(
        public readonly array $types = [],
        public readonly ?TimeRange $timeRange = null,
        public readonly int $limit = 50,
        public readonly ?float $minDurationMs = null,
        public readonly ?string $route = null,
        public readonly ?string $lastRaw = null,
        public readonly ?string $fromRaw = null,
        public readonly ?string $toRaw = null,
        public readonly array $methods = [],
        public readonly array $statuses = [],
        public readonly bool $onlyFailedJobs = false,
        public readonly ?string $connection = null,
        public readonly ?string $search = null,
        public readonly ?string $showUuid = null,
        public readonly bool $includeSensitiveValues = false,
        public readonly array $failOn = [],
    ) {}

    /**
     * Build filters from raw Artisan option values (option name => value).
     *
     * @param  array<string, mixed>  $options
     *
     * @throws InvalidFilter
     */
    public static function fromOptions(array $options): self
    {
        $types = self::resolveTypes($options);
        [$timeRange, $lastRaw, $fromRaw, $toRaw] = self::resolveTimeRange($options);

        $filters = new self(
            types: $types,
            timeRange: $timeRange,
            limit: self::resolveLimit($options['limit'] ?? 50),
            minDurationMs: self::resolveMinDuration($options['min-duration'] ?? null),
            route: self::nullableString($options['route'] ?? null),
            lastRaw: $lastRaw,
            fromRaw: $fromRaw,
            toRaw: $toRaw,
            methods: self::resolveMethods($options['method'] ?? null),
            statuses: self::resolveStatuses($options['status'] ?? null),
            onlyFailedJobs: (bool) ($options['failed'] ?? false),
            connection: self::nullableString($options['connection'] ?? null),
            search: self::nullableString($options['search'] ?? null),
            showUuid: self::nullableString($options['show'] ?? null),
            includeSensitiveValues: self::resolveIncludeSensitive($options['full'] ?? false),
            failOn: self::resolveFailOn($options['fail-on'] ?? null),
        );

        $filters->validateTypeCompatibility();

        return $filters;
    }

    /**
     * Whether the selection is narrowed to specific entry types.
     */
    public function hasTypeSelection(): bool
    {
        return $this->types !== [];
    }

    /**
     * Whether any content-level filter narrows individual rows.
     */
    public function hasContentFilters(): bool
    {
        return $this->minDurationMs !== null
            || $this->route !== null
            || $this->methods !== []
            || $this->statuses !== []
            || $this->onlyFailedJobs
            || $this->connection !== null
            || $this->search !== null;
    }

    /**
     * A stable representation of the filters for JSON output.
     *
     * --show is intentionally not echoed: it selects an entry directly and
     * does not participate in window/filter semantics.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'types' => array_map(fn (EntryType $type): string => $type->value, $this->types),
            'last' => $this->lastRaw,
            'from' => $this->fromRaw,
            'to' => $this->toRaw,
            'resolved_window_utc' => [
                'from' => $this->timeRange?->from?->copy()->utc()->toISOString(),
                'to' => $this->timeRange?->to?->copy()->utc()->toISOString(),
            ],
            'limit' => $this->limit,
            'min_duration_ms' => $this->minDurationMs,
            'route' => $this->route,
            'methods' => $this->methods,
            'statuses' => $this->statuses,
            'failed_jobs_only' => $this->onlyFailedJobs,
            'connection' => $this->connection,
            'search' => $this->search,
            'full' => $this->includeSensitiveValues,
            'fail_on' => $this->failOn,
        ];
    }

    /**
     * Reject combinations where a filter could never match any selected type.
     *
     * @throws InvalidFilter
     */
    private function validateTypeCompatibility(): void
    {
        if (! $this->hasTypeSelection()) {
            return;
        }

        $selected = collect($this->types);

        $rules = [
            '--route' => fn (): bool => $this->route !== null && ! $selected->contains(
                fn (EntryType $t): bool => in_array($t, [EntryType::Request, EntryType::HttpClientRequest], true)
            ),
            '--method' => fn (): bool => $this->methods !== [] && ! $selected->contains(
                fn (EntryType $t): bool => in_array($t, [EntryType::Request, EntryType::HttpClientRequest], true)
            ),
            '--status' => fn (): bool => $this->statuses !== [] && ! $selected->contains(
                fn (EntryType $t): bool => in_array($t, [EntryType::Request, EntryType::HttpClientRequest], true)
            ),
            '--failed' => fn (): bool => $this->onlyFailedJobs && ! $selected->contains(EntryType::Job),
            '--connection' => fn (): bool => $this->connection !== null && ! $selected->contains(
                fn (EntryType $t): bool => in_array($t, [EntryType::Query, EntryType::Job, EntryType::Redis, EntryType::Batch], true)
            ),
            '--min-duration' => fn (): bool => $this->minDurationMs !== null && ! $selected->contains(
                fn (EntryType $t): bool => in_array($t, [EntryType::Request, EntryType::Query, EntryType::Redis, EntryType::HttpClientRequest], true)
            ),
        ];

        foreach ($rules as $flag => $neverApplies) {
            if ($neverApplies()) {
                throw new InvalidFilter("The {$flag} filter does not apply to any of the selected entry types.");
            }
        }
    }

    /**
     * @param  array<string, mixed>  $options
     * @return list<EntryType>
     */
    private static function resolveTypes(array $options): array
    {
        return collect(EntryType::all())
            ->filter(fn (EntryType $type): bool => (bool) ($options[$type->flagName()] ?? false))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{0: TimeRange|null, 1: string|null, 2: string|null, 3: string|null}
     *
     * @throws InvalidFilter
     */
    private static function resolveTimeRange(array $options): array
    {
        $last = self::nullableString($options['last'] ?? null);
        $from = self::nullableString($options['from'] ?? null);
        $to = self::nullableString($options['to'] ?? null);

        if ($last !== null && ($from !== null || $to !== null)) {
            throw new InvalidFilter('--last cannot be combined with --from or --to.');
        }

        try {
            $range = match (true) {
                $last !== null => TimeRange::last($last),
                $from !== null || $to !== null => TimeRange::between($from, $to),
                default => TimeRange::unbounded(),
            };
        } catch (InvalidFilter $e) {
            throw new InvalidFilter($e->getMessage(), previous: $e);
        }

        return [$range, $last, $from, $to];
    }

    /**
     * @throws InvalidFilter
     */
    private static function resolveLimit(mixed $value): int
    {
        if (! is_numeric($value) || (string) (int) $value !== (string) $value || (int) $value < 1) {
            throw new InvalidFilter("The --limit value [{$value}] must be a positive whole number.");
        }

        if ((int) $value > 10000) {
            throw new InvalidFilter('The --limit value must not exceed 10000.');
        }

        return (int) $value;
    }

    /**
     * @return list<string>
     *
     * @throws InvalidFilter
     */
    private static function resolveMethods(?string $value): array
    {
        $known = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];

        return collect(self::splitList($value))
            ->map(fn (string $method): string => strtoupper($method))
            ->each(function (string $method) use ($known): void {
                if (! in_array($method, $known, true)) {
                    throw new InvalidFilter("Unknown HTTP method [{$method}]. Supported methods: ".implode(', ', $known).'.');
                }
            })
            ->values()
            ->all();
    }

    /**
     * @return list<int>
     *
     * @throws InvalidFilter
     */
    private static function resolveStatuses(?string $value): array
    {
        return collect(self::splitList($value))
            ->each(function (string $status): void {
                if (! ctype_digit($status)) {
                    throw new InvalidFilter("The --status value [{$status}] must be an HTTP status code such as 200 or 500.");
                }
            })
            ->map(fn (string $status): int => (int) $status)
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     *
     * @throws InvalidFilter
     */
    private static function resolveFailOn(?string $value): array
    {
        if ($value === null) {
            return [];
        }

        $known = ['exceptions', 'failed-jobs', 'slow-requests', 'slow-queries'];

        $checks = collect(self::splitList($value))
            ->each(function (string $check) use ($known): void {
                if (! in_array($check, $known, true)) {
                    throw new InvalidFilter(sprintf(
                        'Unknown --fail-on check [%s]. Supported checks: %s.',
                        $check,
                        implode(', ', $known)
                    ));
                }
            });

        // De-duplicate while preserving the canonical order of the known checks.
        return collect($known)
            ->filter(fn (string $check): bool => $checks->contains($check))
            ->values()
            ->all();
    }

    /**
     * @throws InvalidFilter
     */
    private static function resolveMinDuration(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        if (! is_numeric($value) || (float) $value < 0) {
            throw new InvalidFilter("The --min-duration value [{$value}] must be a number of milliseconds.");
        }

        return (float) $value;
    }

    private static function resolveIncludeSensitive(mixed $full): bool
    {
        if ((bool) $full) {
            return true;
        }

        // Config opts out of redaction globally; --full opts in per run.
        return config('telescope-inspect.redact_sensitive', true) === false;
    }

    /**
     * Split a comma-separated option into trimmed, non-empty parts.
     *
     * @return list<string>
     */
    private static function splitList(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }

        return array_values(array_filter(
            array_map(trim(...), explode(',', trim($value))),
            fn (string $part): bool => $part !== ''
        ));
    }

    private static function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
