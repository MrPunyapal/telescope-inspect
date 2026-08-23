<?php

namespace MrPunyapal\TelescopeInspect\Analysis;

use Illuminate\Support\Str;
use MrPunyapal\TelescopeInspect\Entries\EntryType;
use MrPunyapal\TelescopeInspect\Entries\NormalizedEntry;
use MrPunyapal\TelescopeInspect\Filters\InspectFilters;
use MrPunyapal\TelescopeInspect\Query\EntryRepository;

/**
 * Groups exception entries into recurring signatures.
 *
 * Grouping uses a class + file + line signature, which is finer-grained
 * than Telescope's family_hash (md5 of file:line); occurrences are counted
 * row-by-row so duplicates Telescope collapsed into earlier entries do not
 * skew counts.
 */
final class ExceptionAnalyzer
{
    /** @var array<string, array<string, mixed>> */
    private array $groups = [];

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
        $scanned = $this->repository->walk(
            [EntryType::Exception],
            $this->filters->timeRange,
            $this->maxRows,
            fn (NormalizedEntry $entry) => $this->collect($entry)
        );

        return [
            'exceptions_analyzed' => $this->count,
            'rows_scanned' => $scanned,
            'distinct_signatures' => count($this->groups),
            'most_frequent' => $this->mostFrequent(),
            'latest' => $this->latest(),
        ];
    }

    private function collect(NormalizedEntry $entry): void
    {
        $this->count++;

        $class = (string) $entry->field('class', 'unknown');
        $file = (string) ($entry->field('file') ?? '');
        $line = (string) ($entry->field('line') ?? '');

        $signature = $class.'@'.$file.':'.$line;

        $group = $this->groups[$signature] ??= [
            'class' => $class,
            'message' => Str::limit((string) $entry->field('message', ''), 300),
            'file' => $entry->field('file'),
            'line' => $entry->field('line'),
            'occurrences' => 0,
            'first_seen_at' => null,
            'last_seen_at' => null,
            '_uuids' => [],
        ];

        $group['occurrences']++;
        $group['_uuids'][] = $entry->uuid;

        $seenAt = $entry->createdAt?->copy()->utc()->toISOString();

        if ($group['last_seen_at'] === null || $group['last_seen_at'] < $seenAt) {
            $group['last_seen_at'] = $seenAt;
        }

        if ($group['first_seen_at'] === null || $group['first_seen_at'] > $seenAt) {
            $group['first_seen_at'] = $seenAt;
        }

        $this->groups[$signature] = $group;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mostFrequent(): array
    {
        return collect($this->groups)
            ->sortByDesc('occurrences')
            ->take(10)
            ->map(fn (array $group): array => collect($group)->except('_uuids')->all())
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function latest(): array
    {
        return collect($this->groups)
            ->sortByDesc('last_seen_at')
            ->take(5)
            ->map(fn (array $group): array => [
                'class' => $group['class'],
                'message' => $group['message'],
                'occurrences' => $group['occurrences'],
                'last_seen_at' => $group['last_seen_at'],
                'sample_uuid' => $group['_uuids'][0] ?? null,
            ])
            ->values()
            ->all();
    }
}
