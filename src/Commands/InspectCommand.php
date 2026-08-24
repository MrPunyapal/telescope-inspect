<?php

namespace MrPunyapal\TelescopeInspect\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use Laravel\AgentDetector\AgentDetector;
use Laravel\Telescope\Telescope;
use MrPunyapal\TelescopeInspect\Analysis\IssueChecks;
use MrPunyapal\TelescopeInspect\Entries\NormalizedEntry;
use MrPunyapal\TelescopeInspect\Filters\InspectFilters;
use MrPunyapal\TelescopeInspect\Filters\InvalidFilter;
use MrPunyapal\TelescopeInspect\InspectionResult;
use MrPunyapal\TelescopeInspect\Normalizers\ContentNormalizer;
use MrPunyapal\TelescopeInspect\Output\HumanPresenter;
use MrPunyapal\TelescopeInspect\Output\JsonPresenter;
use MrPunyapal\TelescopeInspect\Query\EntryRepository;
use MrPunyapal\TelescopeInspect\TelescopeInspector;
use Throwable;

use function Laravel\Prompts\select;

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
                            {--route= : Request URI path or controller action pattern, e.g. "orders/*" or "OrderController@index"}
                            {--method= : Comma-separated HTTP methods (e.g. GET,POST)}
                            {--status= : Comma-separated HTTP status codes (e.g. 500,404)}
                            {--failed : Only failed jobs}
                            {--connection= : Database/queue/Redis connection name}
                            {--search= : Match entry tags or raw content (SQL LIKE wildcards are treated literally)}
                            {--json : Output the machine-readable JSON contract}
                            {--ndjson : Output newline-delimited JSON items; requires a type flag, --batch=<id>, or --show=<uuid>}
                            {--human : Force human-readable output when an AI agent is detected (explicit --json/--ndjson still win)}
                            {--pick : Interactively choose an entry from the listing and open its full detail (requires a type flag or --batch=<id>)}
                            {--full : Include sensitive values Telescope recorded (output may contain secrets)}
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
  0  success (including valid empty results)
  1  failure (Telescope storage missing, UUID or batch id not found)
  2  invalid usage (bad filter values or combinations)
  3  issues found via --fail-on

Notes:
  * Content filters (--route, --method, --status...) apply per matching
    entry type; summaries cover the newest scan_limit rows of the window
    even when row filters narrow the listing.
  * Sensitive values (payloads, bindings, traces, dumps) are redacted
    unless --full is passed.
  * In JSON and NDJSON mode stdout carries only data; diagnostics go to
    stderr as plain text.
  * --watch runs until interrupted by a signal (Ctrl+C); it streams all
    entries of the selected types and cannot apply content filters.
HELP;

    public function handle(
        TelescopeInspector $inspector,
        EntryRepository $repository,
    ): int {
        // An inspection tool should observe, not perturb: recording the
        // command's own queries would pollute later results (and, in watch
        // mode, grow memory for as long as the process lives).
        if (class_exists(Telescope::class)) {
            Telescope::stopRecording();
        }

        // Resolved first so every diagnostic below can respect the stream
        // contract: machine modes keep stdout parseable by routing errors
        // to stderr as plain text.
        $format = $this->resolveOutputFormat();
        $machine = $format->isJson || $format->isNdjson;

        // Structural output checks come first so the most fundamental error
        // is reported before storage or filter problems.
        if ($this->option('ndjson') && $this->option('json')) {
            return $this->invalidUsage('--json and --ndjson cannot be combined.', machine: $machine);
        }

        if ($this->option('fail-on') !== null && $this->option('show') !== null) {
            return $this->invalidUsage('--fail-on cannot be used together with --show.', machine: $machine);
        }

        if ($this->option('fail-on') !== null && $this->option('batch') !== null) {
            return $this->invalidUsage('--fail-on cannot be used together with --batch.', machine: $machine);
        }

        $watchRequested = $this->option('watch') !== null;

        if ($this->option('fail-on') !== null && $watchRequested) {
            return $this->invalidUsage('--fail-on cannot be used together with --watch.', machine: $machine);
        }

        if ($this->option('batch') !== null && $watchRequested) {
            return $this->invalidUsage('--batch cannot be combined with --watch.', machine: $machine);
        }

        if ($this->option('show') !== null && $watchRequested) {
            return $this->invalidUsage('--show cannot be combined with --watch.', machine: $machine);
        }

        try {
            $filters = InspectFilters::fromOptions($this->options());
        } catch (InvalidFilter $error) {
            return $this->invalidUsage($error->getMessage(), machine: $machine);
        }

        if ($watchRequested && $filters->hasContentFilters()) {
            $ignored = ['--min-duration', '--route', '--method', '--status', '--failed', '--connection', '--search'];

            $used = array_values(array_filter($ignored, fn (string $flag): bool => match ($flag) {
                '--min-duration' => $filters->minDurationMs !== null,
                '--route' => $filters->route !== null,
                '--method' => $filters->methods !== [],
                '--status' => $filters->statuses !== [],
                '--failed' => $filters->onlyFailedJobs,
                '--connection' => $filters->connection !== null,
                '--search' => $filters->search !== null,
            }));

            return $this->invalidUsage(
                '--watch streams all entries of the selected types and cannot filter them; remove: '.implode(', ', $used).'.',
                machine: $machine
            );
        }

        if ($this->option('pick') && ! $filters->hasTypeSelection() && $filters->batchId === null) {
            return $this->invalidUsage('--pick requires at least one type flag or --batch=<id>.');
        }

        if ($this->option('pick')) {
            if ($machine) {
                return $this->invalidUsage('--pick cannot be combined with --json or --ndjson.', machine: true);
            }

            if ($watchRequested) {
                return $this->invalidUsage('--pick cannot be combined with --watch.');
            }
        }

        if (! $this->telescopeStorageExists()) {
            $this->runtimeFailure('Telescope storage tables not found.', machine: $machine);
            $this->failHint($machine, 'Run `php artisan migrate` after installing Telescope: composer require laravel/telescope && php artisan telescope:install');

            return Command::FAILURE;
        }

        if ($format->isNdjson && ! $filters->hasTypeSelection() && $filters->showUuid === null && $filters->batchId === null) {
            return $this->invalidUsage('--ndjson requires at least one type flag, --batch=<id>, or --show=<uuid>.', machine: true);
        }

        if ($watchRequested) {
            if (! $filters->hasTypeSelection()) {
                return $this->invalidUsage('--watch requires at least one type flag.', machine: $machine);
            }

            $interval = self::resolveWatchInterval((string) $this->option('watch'));

            if ($interval === null) {
                return $this->invalidUsage('The --watch interval must be a whole number of seconds (at least 1).', machine: $machine);
            }

            return $this->watch($repository, $filters, $format, $interval);
        }

        $result = $inspector->inspect($filters);

        // A missing UUID is a runtime failure in every output mode; machine
        // consumers get the error on stderr and an empty stdout.
        if ($filters->showUuid !== null && $result->singleEntry === null) {
            return $this->runtimeFailure("No Telescope entry found for UUID [{$filters->showUuid}].", machine: $machine);
        }

        // Same for a batch id that matches nothing: scripts must be able to
        // distinguish a typo from an empty-but-valid lifecycle.
        if ($filters->batchId !== null && $result->totalInWindow === 0) {
            return $this->runtimeFailure("No Telescope entries found for batch [{$filters->batchId}].", machine: $machine);
        }

        // Machine modes emit data only; diagnostics would corrupt piped
        // output, so they run exclusively for human rendering.
        if (! $format->isJson && ! $format->isNdjson) {
            if ($filters->search !== null && $filters->timeRange?->from === null) {
                $this->components->warn('--search without a time window scans recent rows; add --last=... on large tables.');
            }

            if ($filters->includeSensitiveValues) {
                $this->components->warn('Sensitive values are included in this output.');
            }
        }

        $violations = IssueChecks::violations($result, $this->slowThresholdFor($result));

        if ($format->isNdjson) {
            return $this->emitNdjson($result, $violations);
        }

        if ($format->isJson) {
            return $this->emitJson($result, $violations, $format->agentName);
        }

        $exit = $this->renderForHumans($result, $violations);

        if ($this->option('pick')) {
            return $this->pickEntry($result, $inspector);
        }

        return $exit;
    }

    /**
     * Resolve the effective output format.
     *
     * Explicit --json/--ndjson win. Otherwise, when the process appears to
     * be running under an AI coding agent (per laravel/agent-detector) and
     * auto switching is enabled in config, the JSON contract is returned so
     * agents never have to parse human tables. --human forces tables back.
     */
    private function resolveOutputFormat(): OutputFormat
    {
        $wantsJson = (bool) $this->option('json');
        $wantsNdjson = (bool) $this->option('ndjson');

        if ($wantsJson || $wantsNdjson || $this->option('human')) {
            return new OutputFormat($wantsJson, $wantsNdjson);
        }

        if ((bool) config('telescope-inspect.auto_json_for_agents', true)) {
            $detected = AgentDetector::detect();

            if ($detected->isAgent) {
                return new OutputFormat(isJson: true, isNdjson: false, agentName: $detected->name);
            }
        }

        return new OutputFormat(false, false);
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
        $this->humanPresenter()->render($result);

        if ($violations === []) {
            return Command::SUCCESS;
        }

        $hints = collect(IssueChecks::known())
            ->filter(fn (string $flag, string $check): bool => in_array($check, $violations, true))
            ->values()
            ->implode(' ');

        $slowHit = array_intersect($violations, ['slow-requests', 'slow-queries']) !== [];
        $threshold = $slowHit ? sprintf(' (slow threshold %sms)', number_format($this->slowThresholdFor($result))) : '';

        $this->components->error('Issues found: '.implode(', ', $violations).$threshold.". Inspect with: {$hints}");

        return self::EXIT_ISSUES_FOUND;
    }

    /**
     * Interactive entry picker (human output only, --pick).
     *
     * Renders a Prompts select over the entries that were just listed and
     * opens the full --show style detail for the chosen one. Falls back to
     * a no-op when no terminal is attached: Prompts returns null for
     * select() without an interactive stream.
     */
    private function pickEntry(InspectionResult $result, TelescopeInspector $inspector): int
    {
        /** @var array<string, string> $options uuid => label */
        $options = [];
        $timezone = config('app.timezone');

        foreach ($result->itemsByType as $typeValue => $entries) {
            foreach ($entries as $entry) {
                $time = $entry->createdAt?->timezone($timezone)->format('H:i:s') ?? '--:--:--';
                $headline = mb_strimwidth((string) $entry->type->headline($entry->fields), 0, 52, '…');
                $location = $this->entryLocationTail($entry);

                $options[$entry->uuid] = sprintf('%s · %s · %s · %s', $time, $entry->type->label(), $headline, $location);
            }
        }

        if ($options === []) {
            $this->components->info('Nothing listed to pick from.');

            return Command::SUCCESS;
        }

        try {
            $uuid = select(
                label: 'Inspect which entry?',
                options: $options,
                scroll: 12,
                hint: 'Enter opens the full detail; Ctrl+C cancels.',
            );
        } catch (Throwable) {
            // Prompts falls back to Symfony's QuestionHelper when no
            // interactive terminal is available (CI, pipes, Windows without
            // a console), which aborts on closed stdin. Treat every prompt
            // failure as a cancellation: the listing above stays useful.
            $this->components->warn('Interactive picker unavailable here; use --show=<uuid> instead.');

            return Command::SUCCESS;
        }

        if (! is_string($uuid) || ! isset($options[$uuid])) {
            return Command::SUCCESS;
        }

        $picked = $inspector->inspect(InspectFilters::fromOptions([
            'show' => $uuid,
            'full' => (bool) $this->option('full'),
        ]));

        if ($picked->singleEntry === null) {
            return $this->runtimeFailure("No Telescope entry found for UUID [{$uuid}].", machine: false);
        }

        $this->humanPresenter()->render($picked);

        return Command::SUCCESS;
    }

    /**
     * Full "file:line" tail for picker labels; labels are not width-capped
     * because Prompts scrolls them horizontally.
     */
    private function entryLocationTail(NormalizedEntry $entry): string
    {
        $file = $entry->field('file');

        if ($file === null) {
            return '-';
        }

        $base = base_path();
        $path = str_starts_with((string) $file, $base) ? substr((string) $file, strlen($base) + 1) : (string) $file;
        $line = $entry->field('line');

        return str_replace('\\', '/', $line !== null ? $path.':'.$line : $path);
    }

    private function humanPresenter(): HumanPresenter
    {
        return new HumanPresenter(
            output: $this->output,
            sensitiveValuesOmitted: ! $this->option('full'),
        );
    }

    /**
     * @param  list<string>  $violations
     */
    private function emitJson(InspectionResult $result, array $violations, ?string $agentName = null): int
    {
        $presenter = new JsonPresenter(
            ndjson: false,
            redactSensitive: ! $result->filters->includeSensitiveValues,
            violations: $violations,
            agentName: $agentName,
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
            $this->emitDiagnostic('Issues found: '.implode(', ', $violations), machine: true);
        }

        return $violations === [] ? Command::SUCCESS : self::EXIT_ISSUES_FOUND;
    }

    private function slowThresholdFor(InspectionResult $result): float
    {
        return (float) ($result->filters->minDurationMs
            ?? config('telescope-inspect.slow_threshold_ms', 500));
    }

    /**
     * Report invalid usage on the correct stream and return the
     * invalid-usage exit code.
     *
     * Machine modes keep stdout parseable: the message goes to stderr as
     * plain text. Human mode keeps Laravel's styled error block.
     */
    private function invalidUsage(string $message, bool $machine = false): int
    {
        $this->emitDiagnostic($message, $machine);

        return Command::INVALID;
    }

    /**
     * Report a runtime failure (missing storage, unknown id) on the correct
     * stream and return the failure exit code.
     */
    private function runtimeFailure(string $message, bool $machine = false): int
    {
        $this->emitDiagnostic($message, $machine);

        return Command::FAILURE;
    }

    private function emitDiagnostic(string $message, bool $machine): void
    {
        if ($machine) {
            // Falls back to the normal output stream when no separate stderr
            // exists (e.g. buffered output in tests), keeping messages visible.
            $this->output->getErrorStyle()->writeln($message);

            return;
        }

        $this->components->error($message);
    }

    /**
     * Emit an additional hint line using the same stream rules.
     */
    private function failHint(bool $machine, string $message): void
    {
        if ($machine) {
            $this->output->getErrorStyle()->writeln($message);

            return;
        }

        $this->components->info($message);
    }

    /**
     * Parse the --watch interval; null signals invalid input.
     */
    private static function resolveWatchInterval(string $value): ?int
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return 2;
        }

        if (! ctype_digit($trimmed) || (int) $trimmed < 1) {
            return null;
        }

        return (int) $trimmed;
    }

    /**
     * Tail new entries as they are recorded (--watch[=seconds]).
     *
     * Starts from the current high-water mark so only fresh traffic prints.
     * Runs until the process is interrupted. Machine modes emit one JSON
     * object per entry on stdout and nothing else; the start-up banner is
     * reserved for human mode.
     */
    private function watch(EntryRepository $repository, InspectFilters $filters, OutputFormat $format, int $interval): int
    {
        $machine = $format->isNdjson || $format->isJson;
        $sequence = $repository->latestSequence();

        if (! $machine) {
            $this->components->info(sprintf(
                'Watching %s. New entries appear below; press Ctrl+C to stop.',
                implode(', ', array_map(fn ($t) => $t->label(), $filters->types))
            ));

            if ($filters->includeSensitiveValues) {
                $this->components->warn('Watch headlines never show sensitive fields; use a JSON mode with --full for those.');
            }
        }

        for (; ;) {
            $page = $repository->findSinceSequence($sequence, $filters->types, 100, includeSensitiveValues: $filters->includeSensitiveValues);

            foreach ($page as $entry) {
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
                    $this->escapeMarkup($entry->type->headline($entry->fields))
                ));
            }

            // A full page means traffic is arriving faster than the poll
            // interval; drain the backlog immediately instead of sleeping.
            if (count($page) < 100) {
                sleep($interval);
            }
        }
    }

    /**
     * Neutralize Symfony style tags in recorded content so entry data can
     * never restyle or spoof terminal output.
     */
    private function escapeMarkup(string $value): string
    {
        return str_replace('<', '<<', $value);
    }
}
