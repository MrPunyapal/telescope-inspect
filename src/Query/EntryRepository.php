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
        public readonly int $scanLimit = 5000,
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
        $this->lastScanTruncated = false;

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
        $candidates = [];
        $scanned = 0;

        foreach ($query->cursor() as $model) {
            $scanned++;

            $entry = $this->hydrate($model->getAttributes(), includeSensitiveValues: $filters->includeSensitiveValues);

            if ($entry === null || ! $this->passesRowFilters($entry, $filters)) {
                continue;
            }

            if ($filters->search !== null) {
                // Candidates are gathered first so every tag lookup for the
                // whole scan happens in a few chunked queries.
                $candidates[] = $entry;

                if (count($candidates) >= $effectiveLimit) {
                    break;
                }

                continue;
            }

            $entries[] = $entry;

            if (count($entries) >= $filters->limit) {
                break;
            }
        }

        if ($candidates !== []) {
            $entries = $this->verifySearchCandidates($candidates, $filters);
        }

        // Truncation means the scan hit its ceiling while the caller asked
        // for more than was found; a cursor that ends early means the whole
        // window was seen and nothing was cut off.
        $this->lastScanTruncated = $effectiveLimit === $this->scanLimit
            && $scanned >= $effectiveLimit
            && count($entries) < $filters->limit;

        return array_slice($entries, 0, $filters->limit);
    }

    /**
     * Find a single entry by UUID, including its tags.
     */
    public function findByUuid(string $uuid, bool $includeSensitiveValues = false): ?NormalizedEntry
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

        return $this->hydrate($row->getAttributes(), $tags, includeSensitiveValues: $includeSensitiveValues);
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
     * All entries that belong to one batch, in chronological order.
     *
     * A batch is everything Telescope recorded during one request or job
     * lifecycle; the batch id is shown by --show and in every JSON item.
     *
     * When the id matches no flush batch, it is retried as a bus batch id
     * (the identifier of an Illuminate\Bus batch): those live in the
     * family_hash column and span multiple flush batches.
     *
     * @return list<NormalizedEntry>
     */
    public function getForBatch(string $batchId): array
    {
        $entries = $this->collectBatchRows(fn (): Builder => EntryModel::query()
            ->where('batch_id', $batchId));

        if ($entries === []) {
            $entries = $this->collectBatchRows(fn (): Builder => EntryModel::query()
                ->where('family_hash', $batchId));
        }

        // A full batch replay must stay bounded like every other read; when
        // the ceiling was reached, consumers see scan.truncated in JSON and
        // a note in human output rather than silently truncated history.
        $this->lastScanTruncated = count($entries) >= $this->scanLimit;

        return $entries;
    }

    /**
     * Walk one batch query under the scan ceiling, chronological order.
     *
     * @param  callable(): Builder<EntryModel>  $query
     * @return list<NormalizedEntry>
     */
    private function collectBatchRows(callable $query): array
    {
        $entries = [];

        foreach ($query()->orderBy('sequence')->limit($this->scanLimit)->cursor() as $row) {
            $entry = $this->hydrate($row->getAttributes(), includeSensitiveValues: true);

            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * Entries recorded after the given sequence id, used for --watch.
     *
     * @param  list<EntryType>  $types
     * @return list<NormalizedEntry>
     */
    public function findSinceSequence(int $sequence, array $types, int $limit = 50, bool $includeSensitiveValues = false): array
    {
        $rows = EntryModel::query()
            ->whereIn('type', array_map(fn (EntryType $t): string => $t->value, $types))
            ->where('sequence', '>', $sequence)
            ->orderBy('sequence')
            ->limit($limit)
            ->get();

        $entries = [];

        foreach ($rows as $row) {
            $entry = $this->hydrate($row->getAttributes(), includeSensitiveValues: $includeSensitiveValues);

            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * The highest sequence id currently stored, the starting point for --watch.
     */
    public function latestSequence(): int
    {
        return (int) (EntryModel::query()->max('sequence') ?? 0);
    }

    /**
     * How many job entries in the window carry Telescope's failed status.
     *
     * Used for the overview hint; reads the JSON status marker directly so
     * no per-row walk is needed. Telescope writes job content compact, so
     * the marker match is exact for standard payloads.
     */
    public function failedJobCount(?TimeRange $range): int
    {
        return $this->baseQuery($range)
            ->where('type', EntryType::Job->value)
            ->where('content', 'like', '%"status":"failed"%')
            ->count();
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

        foreach (array_chunk($batchIds, 999) as $chunk) {
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
     * window, i.e. results may be incomplete.
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
     * Telescope stores content JSON-encoded WITHOUT unescaped slashes or
     * unicode, so a term like "users/create" only appears in storage as
     * "users\/create"; both the raw and the encoded needle are matched.
     *
     * An explicit ESCAPE clause keeps literal % _ \ semantics identical
     * across MySQL, PostgreSQL, and SQLite. After hydration every candidate
     * is re-verified against its normalized fields (and tags) so dialect
     * wildcard differences can never widen results.
     *
     * @param  Builder<EntryModel>  $query
     */
    private function applySearch(Builder $query, ?string $search): void
    {
        if ($search === null) {
            return;
        }

        $patterns = $this->searchPatterns($search);

        $query->where(function ($query) use ($patterns): void {
            // Tag matches...
            $query->orWhereIn('uuid', function ($sub) use ($patterns): void {
                $sub->select('entry_uuid')->from('telescope_entries_tags');

                foreach ($patterns as $index => $pattern) {
                    $index === 0
                        ? $sub->whereRaw('tag LIKE ? ESCAPE ?', [$pattern, '\\'])
                        : $sub->orWhereRaw('tag LIKE ? ESCAPE ?', [$pattern, '\\']);
                }
            });

            // ...or raw content matches, for each needle form.
            foreach ($patterns as $pattern) {
                $query->orWhereRaw('content LIKE ? ESCAPE ?', [$pattern, '\\']);
            }
        });
    }

    /**
     * SQL LIKE patterns for both raw and JSON-escaped needle forms.
     *
     * @return list<string>
     */
    private function searchPatterns(string $search): array
    {
        $escape = fn (string $value): string => str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);

        $patterns = ['%'.$escape($search).'%'];

        $encoded = trim((string) json_encode($search, JSON_INVALID_UTF8_SUBSTITUTE), '"');

        if ($encoded !== '' && $encoded !== $search) {
            $patterns[] = '%'.$escape($encoded).'%';
        }

        return $patterns;
    }

    /**
     * Whether the normalized fields contain the search term.
     *
     * Case handling follows mb_stripos so verification is consistent even
     * where database LIKE collations are not.
     */
    private function searchMatches(string $haystack, string $needle): bool
    {
        return mb_stripos($haystack, $needle) !== false;
    }

    /**
     * Re-verify search candidates against normalized fields and tags so
     * results match one consistent semantic across every database driver.
     * The SQL prefilter may behave differently per dialect; this pass makes
     * the final answer dialect-independent.
     *
     * @param  list<NormalizedEntry>  $candidates
     * @return list<NormalizedEntry>
     */
    private function verifySearchCandidates(array $candidates, InspectFilters $filters): array
    {
        $search = (string) $filters->search;

        $tags = $this->tagsForUuids(array_map(fn (NormalizedEntry $e): string => $e->uuid, $candidates));

        $matches = [];

        foreach ($candidates as $entry) {
            $haystack = (string) json_encode($entry->fields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

            if ($this->searchMatches($haystack, $search)) {
                $matches[] = $entry;

                continue;
            }

            foreach ($tags[$entry->uuid] ?? [] as $tag) {
                if (mb_stripos((string) $tag, $search) !== false) {
                    $matches[] = $entry;

                    break;
                }
            }
        }

        return array_slice($matches, 0, $filters->limit);
    }

    /**
     * Fetch tags for many entries in chunked queries.
     *
     * @param  list<string>  $uuids
     * @return array<string, list<string>> uuid => tags
     */
    private function tagsForUuids(array $uuids): array
    {
        $tags = [];

        foreach (array_chunk($uuids, 999) as $chunk) {
            DB::connection((new EntryModel)->getConnectionName())
                ->table('telescope_entries_tags')
                ->whereIn('entry_uuid', $chunk)
                ->get()
                ->each(function ($row) use (&$tags): void {
                    $tags[(string) $row->entry_uuid][] = (string) $row->tag;
                });
        }

        return $tags;
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

        $createdAt = null;

        if (isset($attributes['created_at'])) {
            try {
                $createdAt = Carbon::parse($attributes['created_at']);
            } catch (\Throwable) {
                $createdAt = null; // Historical rows can carry unparseable timestamps.
            }
        }

        return new NormalizedEntry(
            uuid: (string) $attributes['uuid'],
            type: $type,
            batchId: isset($attributes['batch_id']) ? (string) $attributes['batch_id'] : null,
            createdAt: $createdAt,
            fields: $this->normalizer->normalize($type, $content, $includeSensitiveValues),
            tags: $tags,
            sequence: isset($attributes['sequence']) ? (int) $attributes['sequence'] : null,
        );
    }
}
