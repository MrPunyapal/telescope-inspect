<?php

namespace MrPunyapal\TelescopeInspect;

use Illuminate\Support\Carbon;
use MrPunyapal\TelescopeInspect\Entries\NormalizedEntry;
use MrPunyapal\TelescopeInspect\Filters\InspectFilters;

/**
 * The result of an inspection run.
 *
 * Presenters (and any integration such as an MCP adapter) consume this
 * object; nothing here knows about terminals or JSON encoding. Together
 * with TelescopeInspector and InspectFilters this is the package's
 * supported programmatic API.
 */
final class InspectionResult
{
    /**
     * @param  array<string, int>  $countsByType  type value => entries in window
     * @param  array<string, list<NormalizedEntry>>  $itemsByType  items grouped by type, newest first within each type
     * @param  array<string, array<string, mixed>>  $summariesByType
     * @param  array<string, mixed>|null  $singleEntry
     */
    public function __construct(
        public readonly InspectFilters $filters,
        public readonly Carbon $generatedAt,
        public readonly int $totalInWindow,
        public readonly array $countsByType,
        public readonly array $itemsByType = [],
        public readonly array $summariesByType = [],
        public readonly ?array $singleEntry = null,
        public readonly bool $scanTruncated = false,
        public readonly int $scanLimit = 5000,
        public readonly int $failedJobsInWindow = 0,
    ) {}

    /**
     * All returned items across every requested type.
     *
     * Items are grouped by selected type in canonical order; within each
     * group they are newest first.
     *
     * @return list<NormalizedEntry>
     */
    public function items(): array
    {
        return collect($this->itemsByType)
            ->flatten(1)
            ->values()
            ->all();
    }

    /**
     * Whether the inspection matched zero entries anywhere.
     */
    public function isEmpty(): bool
    {
        return $this->totalInWindow === 0 && $this->singleEntry === null;
    }
}
