<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Telescope\Contracts\EntriesRepository;

/**
 * Fetch the currently monitored tags from Telescope storage.
 *
 * @return list<string>
 */
function monitoredTags(): array
{
    /** @var list<string> $tags */
    $tags = app(EntriesRepository::class)->monitoring();

    return $tags;
}

it('lists monitored tags including the empty state', function () {
    Artisan::call('telescope:monitor', ['action' => 'list']);

    expect(Artisan::output())->toContain('No monitored tags');

    $factory = telescopeFactory();
    DB_monitoring_insert('App\\Jobs\\*');
    expect(monitoredTags())->toBe(['App\\Jobs\\*']);

    Artisan::call('telescope:monitor', ['action' => 'list']);

    expect(Artisan::output())->toContain('App\\Jobs\\*');
});

function DB_monitoring_insert(string $tag): void
{
    DB::table('telescope_monitoring')->insert(['tag' => $tag]);
}

it('adds and removes monitored tags', function () {
    Artisan::call('telescope:monitor', ['action' => 'add', '--tag' => ['App\\Jobs\\Sync*']]);
    Artisan::call('telescope:monitor', ['action' => 'add', '--tag' => ['SlowRequest']]);

    expect(monitoredTags())->toBe(['App\\Jobs\\Sync*', 'SlowRequest']);

    // Adding an existing tag is idempotent.
    Artisan::call('telescope:monitor', ['action' => 'add', '--tag' => ['SlowRequest']]);
    expect(monitoredTags())->toBe(['App\\Jobs\\Sync*', 'SlowRequest']);

    Artisan::call('telescope:monitor', ['action' => 'remove', '--tag' => ['SlowRequest']]);

    expect(monitoredTags())->toBe(['App\\Jobs\\Sync*']);
});

it('rejects unknown actions and missing tags', function () {
    Artisan::call('telescope:monitor', ['action' => 'explode']);

    expect(Artisan::output())->toContain('Unknown action');

    Artisan::call('telescope:monitor', ['action' => 'add']);

    expect(Artisan::output())->toContain('Provide at least one tag');
});
