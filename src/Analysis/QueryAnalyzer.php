<?php

namespace MrPunyapal\TelescopeInspect\Analysis;

use MrPunyapal\TelescopeInspect\Entries\EntryType;
use MrPunyapal\TelescopeInspect\Entries\NormalizedEntry;
use MrPunyapal\TelescopeInspect\Filters\InspectFilters;
use MrPunyapal\TelescopeInspect\Query\EntryRepository;

/**
 * Aggregates query entries: slowest queries, hotspots by normalized SQL,
 * and likely N+1 detection.
 *
 * N+1 detection is heuristic: it flags identical queries repeated many times
 * within a single request batch. It reports likelihood, never certainty.
 */
final class QueryAnalyzer
{
    /** @var array<string, array<string, mixed>> keyed by query hash */
    private array $byHash = [];

    /** @var list<array<string, mixed>> */
    private array $slowest = [];

    private int $count = 0;

    private float $totalMs = 0;

    public function __construct(
        private readonly EntryRepository $repository,
        private readonly InspectFilters $filters,
        private readonly int $maxRows,
        private readonly int $n1Threshold = 10,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function summarize(): array
    {
        $scanned = $this->repository->walk(
            [EntryType::Query],
            $this->filters->timeRange,
            $this->maxRows,
            fn (NormalizedEntry $entry) => $this->collect($entry)
        );

        return [
            'queries_analyzed' => $this->count,
            'rows_scanned' => $scanned,
            'avg_duration_ms' => $this->count > 0 ? round($this->totalMs / $this->count, 2) : null,
            'total_duration_ms' => round($this->totalMs, 2),
            'slowest' => $this->topSlowest(),
            'most_frequent' => $this->mostFrequent(),
            'likely_n_plus_one' => $this->likelyNPlusOne(),
        ];
    }

    private function collect(NormalizedEntry $entry): void
    {
        $this->count++;

        $duration = (float) ($entry->field('duration_ms') ?? 0);
        $this->totalMs += $duration;

        $hash = (string) ($entry->field('query_hash') ?: md5((string) $entry->field('sql', '')));
        $sql = (string) $entry->field('sql', '');

        $group = $this->byHash[$hash] ??= [
            'sql' => $sql,
            'connection' => $entry->field('connection'),
            'executions' => 0,
            'avg_duration_ms' => null,
            'total_duration_ms' => 0.0,
            'slowest_duration_ms' => null,
            'file' => $entry->field('file'),
            'line' => $entry->field('line'),
            '_durations' => [],
            '_batch_ids' => [],
        ];

        $group['executions']++;
        $group['_durations'][] = $duration;
        $group['_batch_ids'][] = $entry->batchId ?? '';
        $group['total_duration_ms'] += $duration;
        $group['slowest_duration_ms'] = max((float) ($group['slowest_duration_ms'] ?? 0), $duration);
        $this->byHash[$hash] = $group;

        // Track slowest individual queries with a bounded insertion.
        if (is_numeric($entry->field('duration_ms'))) {
            $candidate = [
                'uuid' => $entry->uuid,
                'batch_id' => $entry->batchId,
                'sql' => $sql,
                'connection' => $entry->field('connection'),
                'duration_ms' => round($duration, 2),
                'file' => $entry->field('file'),
                'line' => $entry->field('line'),
                'created_at' => $entry->createdAt?->copy()->utc()->toISOString(),
            ];

            if (count($this->slowest) === 10 && (float) $candidate['duration_ms'] <= end($this->slowest)['duration_ms']) {
                return;
            }

            $this->slowest[] = $candidate;

            usort($this->slowest, fn (array $a, array $b): int => $b['duration_ms'] <=> $a['duration_ms']);
            $this->slowest = array_slice($this->slowest, 0, 10);
        }
    }

    /**
     * Query groups ordered by total time spent.
     *
     * @return list<array<string, mixed>>
     */
    private function mostFrequent(): array
    {
        return collect($this->byHash)
            ->map(function (array $group): array {
                $durations = $group['_durations'];
                unset($group['_durations'], $group['_batch_ids']);

                $group['avg_duration_ms'] = Percentiles::mean($durations);
                $group['total_duration_ms'] = round((float) $group['total_duration_ms'], 2);

                return $group;
            })
            ->sortByDesc('total_duration_ms')
            ->take(10)
            ->values()
            ->all();
    }

    /**
     * Individual slowest queries observed in the window.
     *
     * @return list<array<string, mixed>>
     */
    private function topSlowest(): array
    {
        return $this->slowest;
    }

    /**
     * Identical SQL executed many times within one request: the classic
     * N+1 shape. Heuristic only; reported as "likely", never certain.
     *
     * Each offending SQL pattern is reported once, attributed to its worst
     * (most-repeating) request batch.
     *
     * @return list<array<string, mixed>>
     */
    private function likelyNPlusOne(): array
    {
        $candidates = [];

        foreach ($this->byHash as $group) {
            if ((int) $group['executions'] < $this->n1Threshold) {
                continue;
            }

            /** @var array<string, int> $perBatch */
            $perBatch = [];
            foreach ((array) ($group['_batch_ids'] ?? []) as $batchIdValue) {
                $key = (string) $batchIdValue;
                $perBatch[$key] = ($perBatch[$key] ?? 0) + 1;
            }
            arsort($perBatch);

            $worst = null;

            foreach ($perBatch as $batchId => $executions) {
                if ($executions < $this->n1Threshold) {
                    continue;
                }

                if ($worst === null || $executions > $worst['executions_in_batch']) {
                    $worst = [
                        'sql' => $group['sql'],
                        'connection' => $group['connection'],
                        'executions_in_batch' => $executions,
                        'batches_affected' => count($perBatch),
                        'batch_id' => (string) $batchId,
                        'avg_duration_ms' => Percentiles::mean($group['_durations']),
                        'file' => $group['file'],
                        'line' => $group['line'],
                    ];
                }
            }

            if ($worst !== null) {
                $candidates[] = $worst;
            }
        }

        usort($candidates, fn (array $a, array $b): int => $b['executions_in_batch'] <=> $a['executions_in_batch']);

        return array_slice($candidates, 0, 5);
    }
}
