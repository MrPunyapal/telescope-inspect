<?php

namespace MrPunyapal\TelescopeInspect\Output;

use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use MrPunyapal\TelescopeInspect\Entries\EntryType;
use MrPunyapal\TelescopeInspect\Entries\NormalizedEntry;
use MrPunyapal\TelescopeInspect\Filters\InspectFilters;
use MrPunyapal\TelescopeInspect\InspectionResult;
use MrPunyapal\TelescopeInspect\Normalizers\ContentNormalizer;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Renders an InspectionResult as readable terminal output.
 *
 * Design goals: useful summaries first, bounded column widths, no noise,
 * and a clear empty state.
 *
 * @internal
 */
final class HumanPresenter
{
    /** Maximum characters kept in a single table cell. */
    private const CELL_WIDTH = 60;

    public function __construct(
        private readonly SymfonyStyle $output,
        private readonly bool $sensitiveValuesOmitted = true,
    ) {}

    /**
     * Render the complete result.
     */
    public function render(InspectionResult $result): void
    {
        if ($result->singleEntry !== null) {
            $this->renderSingle($result);

            return;
        }

        if (! $result->filters->hasTypeSelection()) {
            $this->renderOverview($result);

            return;
        }

        if ($result->totalInWindow === 0) {
            $this->output->warning('No Telescope entries found'.($result->filters->timeRange !== null ? ' in the selected window' : '').'.');

            return;
        }

        foreach ($result->itemsByType as $typeValue => $entries) {
            $this->renderType($result, EntryType::from($typeValue), $entries);
        }

        if ($result->scanTruncated) {
            $this->output->text('<fg=gray>Note: the newest '.$result->scanLimit.' rows were scanned; older matching entries were not considered.</>');
        }
    }

    /**
     * The default overview shown when no type flags are given.
     */
    private function renderOverview(InspectionResult $result): void
    {
        $this->output->section('Telescope Inspect');

        $this->output->text([
            'Window: '.$this->windowDescription($result->filters),
            'Total entries: '.number_format($result->totalInWindow),
        ]);

        $this->output->newLine();

        if ($result->totalInWindow === 0) {
            $this->output->info('Nothing recorded in this window yet.');

            return;
        }

        $rows = collect(EntryType::all())
            ->filter(fn (EntryType $type): bool => ($result->countsByType[$type->value] ?? 0) > 0)
            ->map(fn (EntryType $type): array => [
                $type->label(),
                number_format($result->countsByType[$type->value]),
                '--'.$type->flagName(),
            ])
            ->values()
            ->all();

        $this->output->table(['Type', 'Entries', 'Inspect with'], $rows);

        $this->output->newLine();
        $this->output->text('<fg=gray>Times shown in the application timezone ('.config('app.timezone').').</>');
        $this->output->text('Tip: combine flags like --requests --queries --last=1h, add --json for machine-readable output.');

        $issues = collect([
            'exceptions' => [$result->countsByType['exception'] ?? 0],
            'failed jobs' => [($result->summariesByType['job']['failed']['total'] ?? 0)],
        ])->filter(fn (array $counts): bool => $counts[0] > 0);

        if ($issues->isNotEmpty()) {
            $summary = $issues->map(fn (array $counts, string $label): string => "{$counts[0]} {$label}")->implode(' and ');
            $this->output->text("Tip: {$summary} recorded — try --exceptions --jobs --last=24h.");
        }
    }

    /**
     * Full-detail view of one entry (--show=<uuid>). Values are shown as
     * normalized (bounded by value_limit); long lines wrap naturally.
     */
    private function renderSingle(InspectionResult $result): void
    {
        $entry = (array) $result->singleEntry;

        if ($this->sensitiveValuesOmitted) {
            $entry = Arr::except($entry, ContentNormalizer::SENSITIVE_FIELDS);
        }

        $type = EntryType::tryFrom((string) ($entry['type'] ?? ''));

        $this->output->section('Telescope Entry'.($type !== null ? ' — '.$type->label() : ''));

        $rows = collect($this->flattenForDisplay($entry))
            ->map(fn ($value, $key): array => [$key, (string) $value])
            ->values()
            ->all();

        $this->output->table(['Key', 'Value'], $rows);

        if ($this->sensitiveValuesOmitted) {
            $this->output->newLine();
            $this->output->text('<fg=gray>Sensitive values are redacted by default. Use --full to include them.</>');
        }
    }

    /**
     * One section per selected entry type.
     *
     * @param  list<NormalizedEntry>  $entries
     */
    private function renderType(InspectionResult $result, EntryType $type, array $entries): void
    {
        $inWindow = $result->countsByType[$type->value] ?? 0;

        $this->output->section(sprintf(
            '%s · showing %d of %s · %s',
            $type->label(),
            count($entries),
            number_format($inWindow),
            $this->windowDescription($result->filters)
        ));

        if ($entries === []) {
            $inWindow = $result->countsByType[$type->value] ?? 0;

            if ($inWindow > 0) {
                $this->output->text("{$type->label()} exist in the window but none are among the newest {$result->filters->limit} entries. Raise --limit, or narrow with filters.");

                return;
            }

            $this->output->text("No {$type->label()} entries here. Widen the window with --last=24h or --from=<date>.");

            return;
        }

        if (count($entries) === $result->filters->limit && $inWindow > count($entries)) {
            $this->output->text('<fg=gray>Limited to '.$result->filters->limit.' entries; raise --limit to see more.</>');
        }

        $summary = $result->summariesByType[$type->value] ?? null;

        match ($type) {
            EntryType::Request => $this->renderRequestSummary($summary, $entries),
            EntryType::Query => $this->renderQuerySummary($summary),
            EntryType::Exception => $this->renderExceptionSummary($summary),
            EntryType::Job => $this->renderJobSummary($summary),
            default => $this->renderGenericTable($type, $entries),
        };

        if ($summary !== null && $result->filters->hasContentFilters()) {
            $this->output->text('<fg=gray>Summary covers the whole window; content filters apply to listings only.</>');
        }
    }

    /**
     * @param  array<string, mixed>|null  $summary
     * @param  list<NormalizedEntry>  $entries
     */
    private function renderRequestSummary(?array $summary, array $entries): void
    {
        if ($summary === null) {
            $this->renderGenericTable(EntryType::Request, $entries);

            return;
        }

        $distribution = collect((array) ($summary['status_distribution'] ?? []))
            ->map(fn (int $count, string $status): string => "{$status}×{$count}")
            ->implode('   ');

        $this->output->text(trim(sprintf(
            'Avg %s · P95 %s%s',
            Duration::milliseconds($summary['avg_duration_ms'] ?? null),
            Duration::milliseconds($summary['p95_duration_ms'] ?? null),
            $distribution !== '' ? ' · Statuses: '.$distribution : ''
        )));

        $routes = (array) ($summary['routes'] ?? []);

        if ($routes !== []) {
            $this->output->table(
                ['Method', 'URI', 'Reqs', 'Avg', 'P95', 'Avg queries'],
                collect($routes)->map(fn (array $route): array => [
                    (string) $route['method'],
                    $this->cell((string) $route['uri'], 36),
                    number_format((int) $route['requests']),
                    Duration::milliseconds($route['avg_duration_ms']),
                    Duration::milliseconds($route['p95_duration_ms']),
                    $route['avg_queries_per_request'] === null ? '-' : (string) $route['avg_queries_per_request'],
                ])->all(),
            );
        }
    }

    /**
     * @param  array<string, mixed>|null  $summary
     */
    private function renderQuerySummary(?array $summary): void
    {
        if ($summary === null) {
            return;
        }

        $this->output->text(sprintf(
            '%s queries analyzed · total %s · avg %s',
            number_format((int) $summary['queries_analyzed']),
            Duration::milliseconds($summary['total_duration_ms'] ?? null),
            Duration::milliseconds($summary['avg_duration_ms'] ?? null),
        ));

        $slowest = (array) ($summary['slowest'] ?? []);

        if ($slowest !== []) {
            $this->output->text('<options=bold>Slowest queries</>');
            $this->output->table(
                ['ms', 'Connection', 'SQL', 'Location'],
                collect($slowest)->map(fn (array $query): array => [
                    (string) $query['duration_ms'],
                    (string) ($query['connection'] ?? '-'),
                    $this->cell((string) $query['sql'], 34),
                    $this->location($query['file'], $query['line'], 22),
                ])->all(),
            );
        }

        $frequent = (array) ($summary['most_frequent'] ?? []);

        if ($frequent !== []) {
            $this->output->text('<options=bold>Most time-consuming query patterns</>');
            $this->output->table(
                ['Executions', 'Total ms', 'Avg ms', 'SQL'],
                collect($frequent)->map(fn (array $group): array => [
                    number_format((int) $group['executions']),
                    (string) $group['total_duration_ms'],
                    (string) $group['avg_duration_ms'],
                    $this->cell((string) $group['sql'], 34),
                ])->all(),
            );
        }

        $n1 = (array) ($summary['likely_n_plus_one'] ?? []);

        if ($n1 !== []) {
            $this->output->text('<options=bold>Likely N+1</> <fg=gray>(heuristic: identical SQL repeated within one request)</>');
            $this->output->table(
                ['Executions', 'Avg ms', 'SQL', 'Location'],
                collect($n1)->map(fn (array $candidate): array => [
                    (string) $candidate['executions_in_batch'],
                    (string) $candidate['avg_duration_ms'],
                    $this->cell((string) $candidate['sql'], 34),
                    $this->location($candidate['file'], $candidate['line'], 22),
                ])->all(),
            );
        }
    }

    /**
     * @param  array<string, mixed>|null  $summary
     */
    private function renderExceptionSummary(?array $summary): void
    {
        if ($summary === null) {
            return;
        }

        $this->output->text(sprintf(
            '%s exceptions · %d distinct signatures',
            number_format((int) $summary['exceptions_analyzed']),
            (int) $summary['distinct_signatures']
        ));

        $groups = (array) ($summary['most_frequent'] ?? []);

        if ($groups !== []) {
            $this->output->table(
                ['Count', 'Class', 'Message', 'Last seen', 'File'],
                collect($groups)->map(fn (array $group): array => [
                    number_format((int) $group['occurrences']),
                    $this->cell((string) $group['class'], 28),
                    $this->cell((string) $group['message'], 30),
                    $this->shortTime((string) ($group['last_seen_at'] ?? '')),
                    $this->location($group['file'], $group['line'], 24),
                ])->all(),
            );
        }
    }

    /**
     * @param  array<string, mixed>|null  $summary
     */
    private function renderJobSummary(?array $summary): void
    {
        if ($summary === null) {
            return;
        }

        $distribution = collect((array) ($summary['status_distribution'] ?? []))
            ->map(fn (int $count, string $status): string => "{$status}×{$count}")
            ->implode('   ');

        $this->output->text(trim(sprintf(
            '%s jobs analyzed%s',
            number_format((int) $summary['jobs_analyzed']),
            $distribution !== '' ? ' · '.$distribution : ''
        )));

        $failures = (array) (($summary['failed'] ?? [])['latest_failures'] ?? []);

        if ($failures !== []) {
            $this->output->table(
                ['Job', 'Queue', 'Exception', 'Failed at'],
                collect($failures)->map(fn (array $failure): array => [
                    $this->cell((string) $failure['job'], 32),
                    (string) $failure['queue'],
                    $this->cell((string) $failure['exception'], 36),
                    $this->shortTime((string) ($failure['failed_at'] ?? '')),
                ])->all(),
            );
        }

        $recurring = (array) (($summary['failed'] ?? [])['recurring_failures'] ?? []);

        if (count($recurring) > 1 || (count($recurring) === 1 && (int) $recurring[0]['failures'] > 1)) {
            $this->output->text('<options=bold>Recurring failures</>');
            $this->output->table(
                ['Job', 'Failures', 'Last failed at'],
                collect($recurring)->map(fn (array $failure): array => [
                    $this->cell((string) $failure['job'], 40),
                    (string) $failure['failures'],
                    $this->shortTime((string) ($failure['last_failed_at'] ?? '')),
                ])->all(),
            );
        }
    }

    /**
     * Fallback listing for types without a dedicated analyzer.
     *
     * @param  list<NormalizedEntry>  $entries
     */
    private function renderGenericTable(EntryType $type, array $entries): void
    {
        $columns = $type->listColumns();

        $headers = array_map(fn (string $column): string => Str::headline($column), $columns);

        $widths = match ($type) {
            EntryType::Dump => [70],
            default => array_fill(0, count($columns), self::CELL_WIDTH),
        };

        $rows = collect($entries)
            ->map(fn ($entry): array => collect($columns)
                ->map(fn (string $column, int $i): string => $this->cell($this->formatField($entry->field($column)), $widths[$i]))
                ->all())
            ->all();

        $this->output->table($headers, $rows);
    }

    /**
     * Format a normalized field value for display.
     */
    private function formatField(mixed $value): string
    {
        if ($value === null) {
            return '-';
        }

        if (is_array($value)) {
            return $value === [] ? '-' : implode(', ', array_map(
                fn ($item): string => is_scalar($item) ? (string) $item : (string) json_encode($item),
                array_values($value)
            ));
        }

        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }

        if (is_float($value)) {
            return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
        }

        return (string) $value;
    }

    /**
     * Flatten an entry array for the detail view, dropping empties.
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function flattenForDisplay(array $entry): array
    {
        $fields = [];

        foreach ($entry as $key => $value) {
            if ($value === null || $value === [] || $value === '') {
                continue;
            }

            $fields[$key] = is_array($value) && ! array_is_list($value)
                ? json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)
                : $this->formatField($value);
        }

        return $fields;
    }

    /**
     * Truncate a value for table display.
     */
    private function cell(string $value, int $width = self::CELL_WIDTH): string
    {
        $value = str_replace(PHP_EOL, ' ', $value);

        return mb_strlen($value) <= $width ? $value : mb_substr($value, 0, max(1, $width - 1)).'…';
    }

    /**
     * Render "file:line" relative to the application base path when possible.
     */
    private function location(mixed $file, mixed $line, int $width = self::CELL_WIDTH): string
    {
        if ($file === null) {
            return '-';
        }

        $base = base_path();
        $path = str_starts_with((string) $file, $base) ? substr((string) $file, strlen($base) + 1) : (string) $file;
        $rendered = $line !== null ? $path.':'.$line : $path;

        return $this->cell(str_replace('\\', '/', $rendered), $width);
    }

    /**
     * Compact timestamp for narrow columns; includes the year when it is not
     * the current one.
     */
    private function shortTime(string $iso): string
    {
        if ($iso === '') {
            return '-';
        }

        try {
            $carbon = Carbon::parse($iso)->timezone(config('app.timezone'));
            $format = $carbon->year === now()->year ? 'm-d H:i' : 'Y-m-d H:i';

            return $carbon->format($format);
        } catch (\Throwable) {
            return $iso;
        }
    }

    private function windowDescription(InspectFilters $filters): string
    {
        if ($filters->lastRaw !== null) {
            return 'last '.$filters->lastRaw;
        }

        if ($filters->fromRaw !== null || $filters->toRaw !== null) {
            return trim(($filters->fromRaw ?? '…').' → '.($filters->toRaw ?? 'now'));
        }

        return 'all time';
    }
}
