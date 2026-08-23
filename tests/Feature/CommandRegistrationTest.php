<?php

use Illuminate\Support\Facades\Artisan;

it('registers the telescope:inspect command', function () {
    expect(array_key_exists('telescope:inspect', Artisan::all()))->toBeTrue();
});

it('shows an empty state when Telescope has no entries', function () {
    inspect()
        ->expectsOutputToContain('Telescope Inspect')
        ->expectsOutputToContain('Total entries: 0')
        ->assertExitCode(0);
});

it('shows per type counts in the overview with hints', function () {
    $factory = telescopeFactory();

    $factory->request(['uri' => 'http://localhost/a']);
    $factory->query('select 1', entryUuid());
    $factory->exception('RuntimeException', 'boom');
    $factory->job('App\Jobs\ShipOrder', 'failed');
    $factory->request(['uri' => 'http://localhost/b']);

    $factory->persist();

    inspect()
        ->expectsOutputToContain('Requests')
        ->expectsOutputToContain('Queries')
        ->expectsOutputToContain('--json')
        ->assertExitCode(0);
});
