<?php

namespace MrPunyapal\TelescopeInspect\Analysis;

use Illuminate\Support\Str;
use MrPunyapal\TelescopeInspect\Entries\EntryType;
use MrPunyapal\TelescopeInspect\Entries\NormalizedEntry;
use MrPunyapal\TelescopeInspect\Filters\InspectFilters;
use MrPunyapal\TelescopeInspect\Query\EntryRepository;

/**
 * Summarizes queued job activity: counts by status and queue, recurring
 * failures, and the most recent failures with their exception messages.
 */
final class JobAnalyzer
{
    private int $count = 0;

    /** @var array<string, int> status => count */
    private array $statuses = [];

    /** @var array<string, int> queue => count */
    private array $queues = [];

    /** @var array<string, array<string, mixed>> failed job name => summary */
    private array $failures = [];

    /** @var list<array<string, mixed>> */
    private array $latestFailures = [];

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
        $scanned = $this->repository->walk(
            [EntryType::Job],
            $this->filters->timeRange,
            $this->maxRows,
            fn (NormalizedEntry $entry) => $this->collect($entry)
        );

        ksort($this->statuses);
        arsort($this->queues);

        return [
            'jobs_analyzed' => $this->count,
            'rows_scanned' => $scanned,
            'status_distribution' => $this->statuses,
            'queues' => $this->queues,
            'failed' => [
                'total' => $this->statuses['failed'] ?? 0,
                'recurring_failures' => $this->recurringFailures(),
                'latest_failures' => $this->latestFailures,
            ],
        ];
    }

    private function collect(NormalizedEntry $entry): void
    {
        $this->count++;

        $status = (string) ($entry->field('status') ?? 'pending');
        $queue = (string) ($entry->field('queue') ?? 'default');
        $name = (string) $entry->field('name', 'unknown');

        $this->statuses[$status] = ($this->statuses[$status] ?? 0) + 1;
        $this->queues[$queue] = ($this->queues[$queue] ?? 0) + 1;

        if ($status === 'failed') {
            if (! isset($this->failures[$name])) {
                $this->failures[$name] = [
                    'job' => $name,
                    'queue' => $queue,
                    'failures' => 0,
                    'last_exception' => null,
                    'last_failed_at' => '',
                ];
            }

            $failure = &$this->failures[$name];

            $failure['failures']++;
            $failure['last_exception'] ??= Str::limit((string) $entry->field('exception_message', ''), 300);
            $failure['last_failed_at'] = max((string) $failure['last_failed_at'], (string) $entry->createdAt?->copy()->utc()->toISOString());

            $this->latestFailures[] = [
                'uuid' => $entry->uuid,
                'job' => $name,
                'queue' => $queue,
                'exception' => Str::limit((string) $entry->field('exception_message', ''), 300),
                'failed_at' => $entry->createdAt?->copy()->utc()->toISOString(),
            ];

            // Newest-first walk, so keep only the most recent ten.
            if (count($this->latestFailures) > 10) {
                array_pop($this->latestFailures);
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recurringFailures(): array
    {
        return collect($this->failures)
            ->sortByDesc('failures')
            ->take(10)
            ->values()
            ->all();
    }
}
