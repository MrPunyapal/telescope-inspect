<?php

namespace MrPunyapal\TelescopeInspect\Analysis;

use MrPunyapal\TelescopeInspect\Entries\EntryType;
use MrPunyapal\TelescopeInspect\Entries\NormalizedEntry;
use MrPunyapal\TelescopeInspect\Filters\InspectFilters;
use MrPunyapal\TelescopeInspect\Query\EntryRepository;

/**
 * Aggregates request entries into route-level statistics.
 *
 * All aggregation happens on the bounded set of most recent matching rows
 * selected by the repository, so memory use stays predictable. Query counts
 * are attributed via the shared flush batch id that Telescope assigns to
 * every entry recorded during one request lifecycle.
 *
 * @internal
 */
final class RequestAnalyzer
{
    public const P95 = 95;

    /** @var array<string, array<string, mixed>> */
    private array $routes = [];

    /** @var list<float> */
    private array $durations = [];

    /** @var array<string, int> */
    private array $statuses = [];

    private ?NormalizedEntry $slowest = null;

    private int $count = 0;

    public function __construct(
        private readonly EntryRepository $repository,
        private readonly InspectFilters $filters,
        private readonly int $maxRows,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function summarize(): array
    {
        $this->repository->walk(
            [EntryType::Request],
            $this->filters->timeRange,
            $this->maxRows,
            $this->collect(...)
        );

        return [
            'requests_analyzed' => $this->count,
            'avg_duration_ms' => Percentiles::mean($this->durations),
            'p95_duration_ms' => Percentiles::percentile($this->durations, self::P95),
            'status_distribution' => $this->sortedStatuses(),
            'slowest_request' => $this->describeSlowest(),
            'routes' => $this->finalizeRoutes(),
        ];
    }

    private function collect(NormalizedEntry $entry): void
    {
        $this->count++;

        $uri = (string) $entry->field('uri', 'unknown');
        $method = (string) $entry->field('method', '?');
        $duration = $entry->field('duration_ms');
        $status = $entry->field('response_status');
        $key = $method.' '.$uri;

        if (! isset($this->routes[$key])) {
            $this->routes[$key] = [
                'method' => $method,
                'uri' => $uri,
                'requests' => 0,
                'avg_duration_ms' => null,
                'p95_duration_ms' => null,
                'avg_queries_per_request' => null,
                'status_codes' => [],
                '_durations' => [],
                '_batches' => [],
            ];
        }

        $route = &$this->routes[$key];

        $route['requests']++;
        // Every entry recorded during one request shares the same batch id;
        // this is what links queries to the request that caused them.
        $route['_batches'][] = $entry->batchId;

        if (is_numeric($duration)) {
            $route['_durations'][] = (float) $duration;
            $this->durations[] = (float) $duration;
        }

        if ($status !== null) {
            $statusKey = (string) $status;
            $route['status_codes'][$statusKey] = ($route['status_codes'][$statusKey] ?? 0) + 1;
            $this->statuses[$statusKey] = ($this->statuses[$statusKey] ?? 0) + 1;
        }

        if ($this->slowest === null || (is_numeric($duration) && (float) $duration > (float) $this->slowest->field('duration_ms', 0))) {
            $this->slowest = $entry;
        }
    }

    /**
     * @return array<string, int>
     */
    private function sortedStatuses(): array
    {
        ksort($this->statuses);

        return $this->statuses;
    }

    /**
     * Attach duration statistics and per-request query counts, then return
     * the slowest routes.
     *
     * @return list<array<string, mixed>>
     */
    private function finalizeRoutes(): array
    {
        $batchIds = [];

        foreach ($this->routes as $route) {
            foreach ($route['_batches'] as $batch) {
                if ($batch !== null && ! in_array($batch, $batchIds, true)) {
                    $batchIds[] = $batch;
                }
            }
        }

        $queryCounts = $this->repository->queryCountsForBatches($batchIds);

        return collect($this->routes)
            ->map(function (array $route) use ($queryCounts): array {
                $durations = $route['_durations'];
                $batches = $route['_batches'];
                unset($route['_durations'], $route['_batches']);

                $route['avg_duration_ms'] = Percentiles::mean($durations);
                $route['p95_duration_ms'] = Percentiles::percentile($durations, self::P95);

                $queryTotal = 0;
                foreach ($batches as $batch) {
                    $queryTotal += (int) $queryCounts->get((string) $batch, 0);
                }
                $route['avg_queries_per_request'] = count($batches) > 0
                                    ? round($queryTotal / count($batches), 1)
                                    : null;

                ksort($route['status_codes']);

                return $route;
            })
            ->sortByDesc(fn (array $route): float => (float) ($route['p95_duration_ms'] ?? $route['avg_duration_ms'] ?? 0))
            ->take(10)
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function describeSlowest(): ?array
    {
        if ($this->slowest === null) {
            return null;
        }

        return [
            'uuid' => $this->slowest->uuid,
            'method' => $this->slowest->field('method'),
            'uri' => $this->slowest->field('uri'),
            'controller_action' => $this->slowest->field('controller_action'),
            'response_status' => $this->slowest->field('response_status'),
            'duration_ms' => $this->slowest->field('duration_ms'),
        ];
    }
}
