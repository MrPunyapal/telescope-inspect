<?php

namespace MrPunyapal\TelescopeInspect\Filters;

use MrPunyapal\TelescopeInspect\Analysis\IssueChecks;
use MrPunyapal\TelescopeInspect\Entries\EntryType;

/**
 * Fully resolved, validated inspection filters.
 *
 * Built from raw Artisan option values via fromOptions(), or from semantic
 * keys via fromArray() for programmatic integrations; construction fails
 * with an InvalidFilter exception when a value cannot be understood or when
 * a filter can never apply to the selected entry types.
 *
 * This class is part of the package's supported public API, together with
 * TelescopeInspector and InspectionResult.
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
        public readonly ?string $batchId = null,
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
            batchId: self::nullableString($options['batch'] ?? null),
            showUuid: self::nullableString($options['show'] ?? null),
            includeSensitiveValues: self::resolveIncludeSensitive($options['full'] ?? false),
            failOn: self::resolveFailOn($options['fail-on'] ?? null),
        );

        $filters->validateBatchExclusivity();
        $filters->validateTypeCompatibility();
        $filters->validateSlowThreshold();

        return $filters;
    }

    /**
     * Build filters from semantic keys — the programmatic counterpart to
     * the Artisan options accepted by fromOptions().
     *
     * Supported keys: types (list of type values or flag names), last,
     * from, to, limit, min_duration_ms (float), route, methods, statuses,
     * failed_jobs_only (bool), connection, search, batch_id, show_uuid,
     * full (bool), fail_on (list of check names).
     *
     * @param  array<string, mixed>  $values
     *
     * @throws InvalidFilter
     */
    public static function fromArray(array $values): self
    {
        return self::fromOptions([
            'requests' => self::wantsType($values, 'request'),
            'queries' => self::wantsType($values, 'query'),
            'exceptions' => self::wantsType($values, 'exception'),
            'jobs' => self::wantsType($values, 'job'),
            'commands' => self::wantsType($values, 'command'),
            'schedule' => self::wantsType($values, 'schedule'),
            'cache' => self::wantsType($values, 'cache'),
            'dumps' => self::wantsType($values, 'dump'),
            'events' => self::wantsType($values, 'event'),
            'gates' => self::wantsType($values, 'gate'),
            'http' => self::wantsType($values, 'client_request'),
            'logs' => self::wantsType($values, 'log'),
            'mail' => self::wantsType($values, 'mail'),
            'models' => self::wantsType($values, 'model'),
            'notifications' => self::wantsType($values, 'notification'),
            'redis' => self::wantsType($values, 'redis'),
            'views' => self::wantsType($values, 'view'),
            'batches' => self::wantsType($values, 'batch'),
            'last' => $values['last'] ?? null,
            'from' => isset($values['from']) ? (string) $values['from'] : null,
            'to' => isset($values['to']) ? (string) $values['to'] : null,
            'limit' => $values['limit'] ?? 50,
            'min-duration' => $values['min_duration_ms'] ?? null,
            'route' => $values['route'] ?? null,
            'method' => is_array($values['methods'] ?? null) ? implode(',', $values['methods']) : ($values['methods'] ?? null),
            'status' => is_array($values['statuses'] ?? null) ? implode(',', array_map(strval(...), $values['statuses'])) : ($values['statuses'] ?? null),
            'failed' => (bool) ($values['failed_jobs_only'] ?? false),
            'connection' => $values['connection'] ?? null,
            'search' => $values['search'] ?? null,
            'batch' => $values['batch_id'] ?? null,
            'show' => $values['show_uuid'] ?? null,
            'full' => (bool) ($values['full'] ?? false),
            'fail-on' => is_array($values['fail_on'] ?? null) ? implode(',', $values['fail_on']) : ($values['fail_on'] ?? null),
        ]);
    }

    /**
     * Whether the requested type value or flag name was selected.
     *
     * @param  list<string>|array<mixed>  $values
     */
    private static function wantsType(array $values, string $type): bool
    {
        $requested = $values['types'] ?? [];

        if (! is_array($requested)) {
            return false;
        }

        foreach ($requested as $name) {
            $candidate = EntryType::tryFrom((string) $name)
                ?? collect(EntryType::all())->first(fn (EntryType $t): bool => $t->flagName() === (string) $name);

            if ($candidate?->value === $type) {
                return true;
            }
        }

        return false;
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
            'batch_id' => $this->batchId,
            'full' => $this->includeSensitiveValues,
            'fail_on' => $this->failOn,
        ];
    }

    /**
     * --batch replays one complete lifecycle, so narrowing filters do not apply.
     *
     * @throws InvalidFilter
     */
    private function validateBatchExclusivity(): void
    {
        if ($this->batchId === null) {
            return;
        }

        $conflicts = collect([
            '--requests' => (bool) ($this->types !== []),
            '--last' => $this->lastRaw !== null,
            '--from' => $this->fromRaw !== null,
            '--to' => $this->toRaw !== null,
            '--min-duration' => $this->minDurationMs !== null,
            '--route' => $this->route !== null,
            '--method' => $this->methods !== [],
            '--status' => $this->statuses !== [],
            '--failed' => $this->onlyFailedJobs,
            '--connection' => $this->connection !== null,
            '--search' => $this->search !== null,
            // A batch replay always shows the whole lifecycle, so a listing
            // limit can only mislead.
            '--limit' => $this->limit !== 50,
        ])
            ->filter(fn (bool $set): bool => $set)
            ->keys();

        if ($conflicts->isNotEmpty()) {
            throw new InvalidFilter(
                '--batch shows the complete batch and cannot be combined with: '.$conflicts->implode(', ').'.'
            );
        }
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
                fn (EntryType $t): bool => $t->supportsHttpFilters()
            ),
            '--method' => fn (): bool => $this->methods !== [] && ! $selected->contains(
                fn (EntryType $t): bool => $t->supportsHttpFilters()
            ),
            '--status' => fn (): bool => $this->statuses !== [] && ! $selected->contains(
                fn (EntryType $t): bool => $t->supportsHttpFilters()
            ),
            '--failed' => fn (): bool => $this->onlyFailedJobs && ! $selected->contains(EntryType::Job),
            '--connection' => fn (): bool => $this->connection !== null && ! $selected->contains(
                fn (EntryType $t): bool => $t->supportsConnectionFilter()
            ),
            '--min-duration' => fn (): bool => $this->minDurationMs !== null && ! $selected->contains(
                fn (EntryType $t): bool => $t->supportsDurationFilter()
            ),
        ];

        foreach ($rules as $flag => $neverApplies) {
            if ($neverApplies()) {
                throw new InvalidFilter("The {$flag} filter does not apply to any of the selected entry types.");
            }
        }
    }

    /**
     * Reject --min-duration=0 combined with slow --fail-on checks: a zero
     * threshold would mark every analyzed entry as slow, guaranteeing a
     * red build that looks like a detection.
     *
     * @throws InvalidFilter
     */
    private function validateSlowThreshold(): void
    {
        if ($this->minDurationMs !== 0.0) {
            return;
        }

        $slowChecks = array_intersect($this->failOn, ['slow-requests', 'slow-queries']);

        if ($slowChecks !== []) {
            throw new InvalidFilter(
                '--min-duration=0 would mark every entry as slow for: '.implode(', ', $slowChecks).
                '. Use a positive value, or drop it to use the configured threshold.'
            );
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

                if ((int) $status < 100) {
                    throw new InvalidFilter("The --status value [{$status}] must be a valid HTTP status code (100-599).");
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

        // IssueChecks is the canonical home of the fail-on vocabulary.
        $known = array_keys(IssueChecks::known());

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
