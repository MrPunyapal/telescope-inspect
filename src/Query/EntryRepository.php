<?php

namespace MrPunyapal\TelescopeInspect\Query;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Telescope\Storage\EntryModel;
use MrPunyapal\TelescopeInspect\Entries\EntryType;
use MrPunyapal\TelescopeInspect\Entries\NormalizedEntry;
use MrPunyapal\TelescopeInspect\Filters\InspectFilters;
use MrPunyapal\TelescopeInspect\Filters\TimeRange;
use MrPunyapal\TelescopeInspect\Normalizers\ContentNormalizer;

/**
 * The single data-access layer for Telescope entries.
 *
 * Filtering that can happen in SQL does; content-level filters (route,
 * status, duration...) are applied while walking a bounded number of
 * newest-first rows so memory stays predictable on large installations.
 *
 * @internal
 */
final class EntryRepository
{
    public function __construct(
        private readonly ContentNormalizer $normalizer,
        private readonly int $scanLimit = 5000,
    ) {}

    /**
     * Entry counts per inspectable type within the given time range.
     *
     * @return Collection<string, int> type value => count, only non-zero counts
     */
    public function countsPerType(?TimeRange $range): Collection
    {
        return $this->baseQuery($range)
            ->selectRaw('type, count(*) as aggregate')
            ->groupBy('type')
            ->pluck('aggregate', 'type');
    }

    /**
     * Fetch normalized entries of the selected types, newest first.
     *
     * Content-level filters are applied while walking the most recent rows;
     * at most scanLimit rows are inspected to satisfy the requested limit.
     *
     * @return list<NormalizedEntry>
     */
    public function get(InspectFilters $filters): array
    {
        if (! $filters->hasTypeSelection()) {
            return [];
        }

        // Without content-level filters every scanned row matches, so the
        // database limit can be exactly what we will display.
        $effectiveLimit = $filters->hasContentFilters()
            ? $this->scanLimit
            : min($this->scanLimit, $filters->limit);

        $query = $this->baseQuery($filters->timeRange)
            ->whereIn('type', array_map(fn (EntryType $t): string => $t->value, $filters->types))
            ->orderByDesc('sequence')
            ->limit($effectiveLimit);

        $this->applySearch($query, $filters->search);

        $entries = [];

        foreach ($query->cursor() as $model) {
            $entry = $this->hydrate($model->getAttributes(), includeSensitiveValues: $filters->includeSensitiveValues);

            if ($entry === null || ! $this->passesRowFilters($entry, $filters)) {
                continue;
            }

            $entries[] = $entry;

            if (count($entries) >= $filters->limit) {
                break;
            }
        }

        $this->lastScanTruncated = count($entries) < $filters->limit
            && $effectiveLimit === $this->scanLimit;

        return $entries;
    }

    /**
     * Find a single entry by UUID, including its tags.
     */
    public function findByUuid(string $uuid): ?NormalizedEntry
    {
        $row = EntryModel::query()->where('uuid', $uuid)->first();

        if ($row === null) {
            return null;
        }

        $tags = DB::connection($row->getConnectionName())
            ->table('telescope_entries_tags')
            ->where('entry_uuid', $uuid)
            ->pluck('tag')
            ->all();

        return $this->hydrate($row->getAttributes(), $tags, includeSensitiveValues: true);
    }

    /**
     * Walk up to maxRows entries of the given types (newest first), passing
     * each to the callback. Returns how many rows were scanned.
     *
     * @param  list<EntryType>  $types
     */
    public function walk(array $types, ?TimeRange $range, int $maxRows, callable $callback): int
    {
        $scanned = 0;

        $query = $this->baseQuery($range)
            ->whereIn('type', array_map(fn (EntryType $t): string => $t->value, $types))
            ->orderByDesc('sequence')
            ->limit(min($maxRows, $this->scanLimit));

        foreach ($query->cursor() as $model) {
            $scanned++;

            $entry = $this->hydrate($model->getAttributes());

            if ($entry !== null) {
                $callback($entry);
            }
        }

        return $scanned;
    }

    /**
     * Count query entries grouped by batch id, for the given batch ids.
     *
     * Used to attribute database work to the requests that caused it; all
     * entries recorded during one request share a single flush batch id.
     *
     * @param  list<string>  $batchIds
     * @return Collection<string, int> batch id => query count
     */
    public function queryCountsForBatches(array $batchIds): Collection
    {
        $counts = collect();

        foreach (array_chunk($batchIds, 1000) as $chunk) {
            $counts = $counts->merge(
                $this->baseQuery(null)
                    ->whereIn('type', [EntryType::Query->value])
                    ->whereIn('batch_id', $chunk)
                    ->selectRaw('batch_id, count(*) as aggregate')
                    ->groupBy('batch_id')
                    ->pluck('aggregate', 'batch_id')
            );
        }

        return $counts;
    }

    /**
     * Whether the newest-first scan hit its ceiling without exhausting the
     * window — i.e. results may be incomplete.
     */
    public function lastScanWasTruncated(): bool
    {
        return $this->lastScanTruncated;
    }

    private bool $lastScanTruncated = false;

    /**
     * The base query against Telescope's storage with time filtering applied.
     *
     * @return Builder<EntryModel>
     */
    private function baseQuery(?TimeRange $range): Builder
    {
        $query = EntryModel::query();

        if ($range?->from !== null) {
            $query->where('created_at', '>=', $range->from);
        }

        if ($range?->to !== null) {
            $query->where('created_at', '<=', $range->to);
        }

        return $query;
    }

    /**
     * Apply --search across Telescope tags and raw content.
     *
     * Note: leading-wildcard LIKE cannot use indexes; this is acceptable for
     * a bounded CLI tool but can be slow on very large tables.
     *
     * @param  Builder<EntryModel>  $query
     */
    private function applySearch(Builder $query, ?string $search): void
    {
        if ($search === null) {
            return;
        }

        $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $search).'%';

        $query->where(function ($query) use ($pattern): void {
            $query
                ->whereIn('uuid', function ($sub) use ($pattern): void {
                    $sub->select('entry_uuid')
                        ->from('telescope_entries_tags')
                        ->where('tag', 'like', $pattern);
                })
                ->orWhere('content', 'like', $pattern);
        });
    }

    /**
     * Apply filters that live inside the decoded JSON content.
     */
    private function passesRowFilters(NormalizedEntry $entry, InspectFilters $filters): bool
    {
        if ($filters->minDurationMs !== null && ! $this->typeHasDuration($entry->type)) {
            return true; // Filters are type-scoped; other types are unaffected.
        }

        if ($filters->minDurationMs !== null) {
            $duration = $entry->field('duration_ms');

            if (! is_numeric($duration) || (float) $duration < $filters->minDurationMs) {
                return false;
            }
        }

        if ($filters->methods !== [] && $this->typeHasHttpFields($entry->type)
            && ! in_array(strtoupper((string) $entry->field('method')), $filters->methods, true)) {
            return false;
        }

        if ($filters->statuses !== [] && $this->typeHasHttpFields($entry->type)
            && ! in_array($entry->field('response_status'), $filters->statuses, true)) {
            return false;
        }

        if ($filters->onlyFailedJobs && $entry->type === EntryType::Job && $entry->field('status') !== 'failed') {
            return false;
        }

        if ($filters->connection !== null && $this->typeHasConnection($entry->type)
            && $entry->field('connection') !== $filters->connection) {
            return false;
        }

        if ($filters->route !== null && $this->typeHasHttpFields($entry->type)
            && ! $this->matchesRoute($entry, $filters->route)) {
            return false;
        }

        return true;
    }

    private function typeHasDuration(EntryType $type): bool
    {
        return in_array($type, [EntryType::Request, EntryType::Query, EntryType::Redis, EntryType::HttpClientRequest], true);
    }

    private function typeHasHttpFields(EntryType $type): bool
    {
        return in_array($type, [EntryType::Request, EntryType::HttpClientRequest], true);
    }

    private function typeHasConnection(EntryType $type): bool
    {
        return in_array($type, [EntryType::Query, EntryType::Job, EntryType::Redis, EntryType::Batch], true);
    }

    /**
     * Match --route patterns against the URI and controller action.
     */
    private function matchesRoute(NormalizedEntry $entry, string $pattern): bool
    {
        foreach (['uri', 'controller_action'] as $key) {
            $value = $entry->field($key);

            if (is_string($value) && (fnmatch($pattern, $value) || str_contains(strtolower($value), strtolower($pattern)))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Decode one storage row into a NormalizedEntry.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $tags
     */
    private function hydrate(array $attributes, array $tags = [], bool $includeSensitiveValues = false): ?NormalizedEntry
    {
        $type = EntryType::tryFrom((string) ($attributes['type'] ?? ''));

        if ($type === null) {
            return null;
        }

        $content = $attributes['content'] ?? [];

        if (is_string($content)) {
            $decoded = json_decode($content, true);
            $content = is_array($decoded) ? $decoded : [];
        }

        $createdAt = isset($attributes['created_at'])
            ? Carbon::parse($attributes['created_at'])
            : null;

        return new NormalizedEntry(
            uuid: (string) $attributes['uuid'],
            type: $type,
            batchId: isset($attributes['batch_id']) ? (string) $attributes['batch_id'] : null,
            createdAt: $createdAt,
            fields: $this->normalizer->normalize($type, $content, $includeSensitiveValues),
            tags: $tags,
        );
    }
}
