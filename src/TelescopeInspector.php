<?php

namespace MrPunyapal\TelescopeInspect;

use MrPunyapal\TelescopeInspect\Analysis\ExceptionAnalyzer;
use MrPunyapal\TelescopeInspect\Analysis\JobAnalyzer;
use MrPunyapal\TelescopeInspect\Analysis\QueryAnalyzer;
use MrPunyapal\TelescopeInspect\Analysis\RequestAnalyzer;
use MrPunyapal\TelescopeInspect\Entries\EntryType;
use MrPunyapal\TelescopeInspect\Filters\InspectFilters;
use MrPunyapal\TelescopeInspect\Query\EntryRepository;

/**
 * The package's core application service.
 *
 * Turns validated filters into an InspectionResult by combining storage
 * queries with per-type analysis. The Artisan command is a thin wrapper
 * around this class, and future integrations (MCP adapters, IDE tooling)
 * should consume this service directly instead of duplicating query logic.
 *
 * @internal
 */
final class TelescopeInspector
{
    public function __construct(
        private readonly EntryRepository $repository,
    ) {}

    /**
     * Run an inspection for the given filters.
     */
    public function inspect(InspectFilters $filters): InspectionResult
    {
        $countsByType = $this->repository->countsPerType($filters->timeRange)
            ->mapWithKeys(fn ($count, string $type): array => [$type => (int) $count])
            ->all();

        $totalInWindow = array_sum($countsByType);

        if ($filters->showUuid !== null) {
            return new InspectionResult(
                filters: $filters,
                generatedAt: now(),
                totalInWindow: $totalInWindow,
                countsByType: $countsByType,
                singleEntry: $this->repository->findByUuid($filters->showUuid)?->toArray(),
                scanLimit: $this->scanLimit(),
            );
        }

        // One bounded fetch, then partition by type — never one query per type.
        $entries = $filters->hasTypeSelection() ? $this->repository->get($filters) : [];

        $itemsByType = [];
        foreach ($entries as $entry) {
            $itemsByType[$entry->type->value][] = $entry;
        }

        foreach ($filters->types as $type) {
            $itemsByType[$type->value] ??= [];
        }

        $summariesByType = $this->buildSummaries($filters, $countsByType, $filters->types);

        return new InspectionResult(
            filters: $filters,
            generatedAt: now(),
            totalInWindow: $totalInWindow,
            countsByType: $countsByType,
            itemsByType: $itemsByType,
            summariesByType: $summariesByType,
            scanTruncated: $this->repository->lastScanWasTruncated(),
            scanLimit: $this->scanLimit(),
        );
    }

    /**
     * Analysis summaries for selected types plus any type referenced by a
     * --fail-on check (so CI checks can never silently pass because the
     * matching flag was absent).
     *
     * @param  array<string, int>  $countsByType
     * @param  list<EntryType>  $selected
     * @return array<string, array<string, mixed>>
     */
    private function buildSummaries(InspectFilters $filters, array $countsByType, array $selected): array
    {
        $required = collect([
            'exceptions' => EntryType::Exception,
            'failed-jobs' => EntryType::Job,
            'slow-requests' => EntryType::Request,
            'slow-queries' => EntryType::Query,
        ])
            ->filter(fn (EntryType $type, string $check): bool => in_array($check, $filters->failOn, true))
            ->values()
            ->merge($selected)
            ->unique();

        $summaries = [];

        foreach ($required as $type) {
            if (($countsByType[$type->value] ?? 0) === 0 || isset($summaries[$type->value])) {
                continue;
            }

            $summary = $this->summarize($type, $filters);

            if ($summary !== null) {
                $summaries[$type->value] = $summary;
            }
        }

        return $summaries;
    }

    /**
     * Analysis summary for types that support it; null otherwise.
     *
     * @return array<string, mixed>|null
     */
    private function summarize(EntryType $type, InspectFilters $filters): ?array
    {
        $maxRows = (int) config('telescope-inspect.scan_limit', 5000);

        return match ($type) {
            EntryType::Request => (new RequestAnalyzer($this->repository, $filters, $maxRows))->summarize(),
            EntryType::Query => (new QueryAnalyzer($this->repository, $filters, $maxRows))->summarize(),
            EntryType::Exception => (new ExceptionAnalyzer($this->repository, $filters, $maxRows))->summarize(),
            EntryType::Job => (new JobAnalyzer($this->repository, $filters, $maxRows))->summarize(),
            default => null,
        };
    }

    private function scanLimit(): int
    {
        return (int) config('telescope-inspect.scan_limit', 5000);
    }
}
