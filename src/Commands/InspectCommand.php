<?php

namespace MrPunyapal\TelescopeInspect\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use MrPunyapal\TelescopeInspect\Analysis\IssueChecks;
use MrPunyapal\TelescopeInspect\Filters\InspectFilters;
use MrPunyapal\TelescopeInspect\Filters\InvalidFilter;
use MrPunyapal\TelescopeInspect\InspectionResult;
use MrPunyapal\TelescopeInspect\Normalizers\ContentNormalizer;
use MrPunyapal\TelescopeInspect\Output\HumanPresenter;
use MrPunyapal\TelescopeInspect\Output\JsonPresenter;
use MrPunyapal\TelescopeInspect\Query\EntryRepository;
use MrPunyapal\TelescopeInspect\TelescopeInspector;
use Throwable;

/**
 * php artisan telescope:inspect
 *
 * Query, filter, inspect, summarize and export Telescope data from the
 * command line. Human output by default; canonical JSON with --json.
 */
class InspectCommand extends Command
{
    /**
     * Exit codes are part of the public contract for scripts and CI.
     */
    public const EXIT_ISSUES_FOUND = 3;

    protected $signature = 'telescope:inspect
                            {--requests : Inspect incoming HTTP requests}
                            {--queries : Inspect database queries}
                            {--exceptions : Inspect exceptions}
                            {--jobs : Inspect queued jobs}
                            {--commands : Inspect Artisan command executions}
                            {--schedule : Inspect scheduled task executions}
                            {--cache : Inspect cache operations}
                            {--dumps : Inspect variable dumps}
                            {--events : Inspect application events}
                            {--gates : Inspect gate / policy checks}
                            {--http : Inspect outgoing HTTP client requests (not incoming traffic)}
                            {--logs : Inspect log messages}
                            {--mail : Inspect sent mail}
                            {--models : Inspect Eloquent model events}
                            {--notifications : Inspect notifications}
                            {--redis : Inspect Redis commands}
                            {--views : Inspect rendered views}
                            {--batches : Inspect job batches}
                            {--show= : Show full detail for a single entry UUID (ignores other filters)}
                            {--batch= : Replay every entry recorded in one request or job lifecycle}
                            {--watch= : Keep running and print new entries as they arrive; optional seconds between checks (default 2)}
                            {--last= : Only entries newer than this duration (e.g. 15m, 1h, 7d); default: all time}
                            {--from= : Window start date/time in the app timezone (e.g. "2026-08-01 14:30")}
                            {--to= : Window end date/time in the app timezone (e.g. "2026-08-02")}
                            {--limit=50 : Maximum entries shown across the selected types}
                            {--min-duration= : Only entries that took at least this many milliseconds (requests, queries, redis, http); also sets the --fail-on slow threshold}
                            {--route= : Request URI or controller action pattern, e.g. "orders/*" or "OrderController@index"}
                            {--method= : Comma-separated HTTP methods (e.g. GET,POST)}
                            {--status= : Comma-separated HTTP status codes (e.g. 500,404)}
                            {--failed : Only failed jobs}
                            {--connection= : Database/queue/Redis connection name}
                            {--search= : Match entry tags or raw content}
                            {--json : Output the machine-readable JSON contract}
                            {--ndjson : Output newline-delimited JSON items (requires a type flag or --show)}
                            {--full : Include sensitive values Telescope recorded — output may contain secrets}
                            {--fail-on= : Exit with code 3 when issues exist: exceptions,failed-jobs,slow-requests,slow-queries}';

    protected $description = 'Inspect and summarize Laravel Telescope data from the command line';

    /** @var string */
    protected $help = <<<'HELP'
Type flags are plural for countable types (--requests, --queries) and
singular for uncountable ones (--cache, --mail, --redis).

With no type flags the command prints an overview of everything recorded.

Examples:
  artisan telescope:inspect --requests --last=1h
  artisan telescope:inspect --queries --min-duration=500 --limit=20
  artisan telescope:inspect --jobs --failed --last=24h
  artisan telescope:inspect --requests --queries --exceptions --last=1h --json
  artisan telescope:inspect --fail-on=exceptions,failed-jobs --last=15m

Exit codes:
  0  success
  1  failure (Telescope storage missing, UUID not found)
  2  invalid usage (bad filter values or combinations)
  3  issues found via --fail-on

Notes:
  * Content filters (--route, --method, --status...) apply per matching
    entry type; summaries cover the selected window even when row filters
    narrow the listing.
  * Sensitive values (payloads, bindings, traces) are redacted unless
    --full is passed.
HELP;

    public function handle(
        TelescopeInspector $inspector,
        EntryRepository $repository,
    ): int {
        if (! $this->telescopeStorageExists()) {
            $this->components->error('Telescope storage tables not found.');
            $this->components->info('Run `php artisan migrate` after installing Telescope: composer require laravel/telescope && php artisan telescope:install');

            return Command::FAILURE;
        }

        // Structural output checks come before filter validation so the most
        // fundamental error is reported first.
        if ($this->option('ndjson') && $this->option('json')) {
            $this->components->error('Use either --json or --ndjson, not both.');

            return Command::INVALID;
        }

        if ($this->option('fail-on') !== null && $this->option('show') !== null) {
            $this->components->error('--fail-on cannot be used together with --show.');

            return Command::INVALID;
        }

        $watchRequested = $this->option('watch') !== null;

        if ($this->option('batch') !== null && $watchRequested) {
            $this->components->error('--batch cannot be combined with --watch.');

            return Command::INVALID;
        }

        try {
            $filters = InspectFilters::fromOptions($this->options());
        } catch (InvalidFilter $error) {
            $this->components->error($error->getMessage());

            return Command::INVALID;
        }

        if ($this->option('ndjson') && ! $filters->hasTypeSelection() && $filters->showUuid === null) {
            $this->components->error('--ndjson requires at least one type flag or --show=<uuid>.');

            return Command::INVALID;
        }

        if ($watchRequested) {
            if (! $filters->hasTypeSelection()) {
                $this->components->error('--watch requires at least one type flag.');

                return Command::INVALID;
            }

            return $this->watch($repository, $filters);
        }

        $result = $inspector->inspect($filters);

        if ($filters->showUuid !== null && $result->singleEntry === null && ! $this->option('json')) {
            $this->components->error("No Telescope entry found for UUID [{$filters->showUuid}].");

            return Command::FAILURE;
        }

        // Machine modes emit data only; diagnostics would corrupt piped
        // output, so they run exclusively for human rendering.
        if (! $this->option('json') && ! $this->option('ndjson')) {
            if ($filters->search !== null && $filters->timeRange?->from === null) {
                $this->components->warn('--search without a time window scans recent rows; add --last=... on large tables.');
            }

            if ($filters->includeSensitiveValues) {
                $this->components->warn('Sensitive values are included in this output.');
            }
        }

        $violations = IssueChecks::violations($result, $this->slowThresholdFor($result));

        if ($this->option('ndjson')) {
            return $this->emitNdjson($result, $violations);
        }

        if ($this->option('json')) {
            return $this->emitJson($result, $violations);
        }

        return $this->renderForHumans($result, $violations);
    }

    /**
     * Whether Telescope's storage tables appear to be available.
     */
    private function telescopeStorageExists(): bool
    {
        try {
            return Schema::connection((string) config('telescope.storage.database.connection'))
                ->hasTable('telescope_entries');
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Human rendering plus --fail-on evaluation.
     *
     * @param  list<string>  $violations
     */
    private function renderForHumans(InspectionResult $result, array $violations): int
    {
        (new HumanPresenter(
            output: $this->output,
            sensitiveValuesOmitted: ! $result->filters->includeSensitiveValues,
        ))->render($result);

        if ($violations === []) {
            return Command::SUCCESS;
        }

        $hints = collect(IssueChecks::known())
            ->filter(fn (string $flag, string $check): bool => in_array($check, $violations, true))
            ->values()
            ->implode(' ');

        $this->components->error('Issues found: '.implode(', ', $violations).". Inspect with: {$hints}");

        return self::EXIT_ISSUES_FOUND;
    }

    /**
     * @param  list<string>  $violations
     */
    private function emitJson(InspectionResult $result, array $violations): int
    {
        $presenter = new JsonPresenter(
            ndjson: false,
            redactSensitive: ! $result->filters->includeSensitiveValues,
            violations: $violations,
        );

        // stdout carries only the document; violations are structured inside it.
        $this->line($presenter->render($result));

        return $violations === [] ? Command::SUCCESS : self::EXIT_ISSUES_FOUND;
    }

    /**
     * @param  list<string>  $violations
     */
    private function emitNdjson(InspectionResult $result, array $violations): int
    {
        $presenter = new JsonPresenter(
            ndjson: true,
            redactSensitive: ! $result->filters->includeSensitiveValues,
        );

        $output = $presenter->render($result);

        if ($output !== '') {
            $this->line($output);
        }

        if ($violations !== []) {
            $this->output->getErrorStyle()->error('Issues found: '.implode(', ', $violations));
        }

        return $violations === [] ? Command::SUCCESS : self::EXIT_ISSUES_FOUND;
    }

    private function slowThresholdFor(InspectionResult $result): float
    {
        return (float) ($result->filters->minDurationMs
            ?? config('telescope-inspect.slow_threshold_ms', 500));
    }

    /**
     * Tail new entries as they are recorded (--watch[=seconds]).
     *
     * Starts from the current high-water mark so only fresh traffic prints.
     * Runs until the process is interrupted.
     */
    private function watch(EntryRepository $repository, InspectFilters $filters): int
    {
        $interval = max(1, (int) ((string) ($this->option('watch') ?: 2)));

        $machine = $this->option('ndjson');
        $sequence = $repository->latestSequence();

        $this->components->info(sprintf(
            'Watching %s. New entries appear below; press Ctrl+C to stop.',
            implode(', ', array_map(fn ($t) => $t->label(), $filters->types))
        ));

        for (; ;) {
            foreach ($repository->findSinceSequence($sequence, $filters->types, 100) as $entry) {
                $sequence = max($sequence, $entry->sequence ?? $sequence);

                if ($machine) {
                    $item = $entry->toArray();

                    if (! $filters->includeSensitiveValues) {
                        $item = Arr::except($item, ContentNormalizer::SENSITIVE_FIELDS);
                    }

                    $this->line((string) json_encode($item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));

                    continue;
                }

                $this->line(sprintf(
                    '%s  %-12s %s',
                    $entry->createdAt?->timezone(config('app.timezone'))->format('H:i:s') ?? str_repeat(' ', 8),
                    '<fg=cyan>'.$entry->type->label().'</>',
                    $entry->type->headline($entry->fields)
                ));
            }

            sleep($interval);
        }
    }
}
