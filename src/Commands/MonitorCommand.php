<?php

namespace MrPunyapal\TelescopeInspect\Commands;

use Illuminate\Console\Command;
use Laravel\Telescope\Contracts\EntriesRepository;

/**
 * php artisan telescope:monitor
 *
 * Manage Telescope's monitored tags from the CLI. Entries carrying a
 * monitored tag are stored even when recording is paused, which makes this
 * the companion to production debugging workflows.
 */
class MonitorCommand extends Command
{
    protected $signature = 'telescope:monitor
                            {action : list | add | remove}
                            {--tag=* : One or more tags, e.g. "App\\Jobs\\*" or "Auth:id:7"}';

    protected $description = 'List, add, or remove Telescope monitored tags';

    public function handle(EntriesRepository $repository): int
    {
        $action = (string) $this->argument('action');
        $tags = collect($this->option('tag'))
            ->map(fn ($tag): string => trim((string) $tag))
            ->filter()
            ->values()
            ->all();

        return match ($action) {
            'list' => $this->listTags($repository),
            'add' => $this->modify($repository, $tags, add: true),
            'remove' => $this->modify($repository, $tags, add: false),
            default => $this->unknownAction($action),
        };
    }

    private function listTags(EntriesRepository $repository): int
    {
        /** @var list<string> $tags */
        $tags = $repository->monitoring();

        if ($tags === []) {
            $this->components->info('No monitored tags. Add one with: php artisan telescope:monitor add --tag="App\\Jobs\\*"');

            return Command::SUCCESS;
        }

        $this->table(['Monitored tag'], array_map(fn (string $tag): array => [$tag], $tags));
        $this->components->info('Entries carrying these tags are recorded even when recording is paused.');

        return Command::SUCCESS;
    }

    /**
     * @param  list<string>  $tags
     */
    private function modify(EntriesRepository $repository, array $tags, bool $add): int
    {
        if ($tags === []) {
            $this->components->error(sprintf(
                'Provide at least one tag: php artisan telescope:monitor %s --tag="..."',
                $add ? 'add' : 'remove'
            ));

            return Command::INVALID;
        }

        if ($add) {
            $repository->monitor($tags);
        } else {
            $repository->stopMonitoring($tags);
        }

        foreach ($tags as $tag) {
            $add
                ? $this->components->info("Now monitoring [{$tag}].")
                : $this->components->info("Stopped monitoring [{$tag}].");
        }

        return Command::SUCCESS;
    }

    private function unknownAction(string $action): int
    {
        $this->components->error("Unknown action [{$action}]. Use list, add, or remove.");

        return Command::INVALID;
    }
}
