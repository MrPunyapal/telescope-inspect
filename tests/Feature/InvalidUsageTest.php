<?php

use Illuminate\Support\Facades\Schema;

it('rejects invalid limit values', function (string $value) {
    inspect(['requests' => true, 'limit' => $value])
        ->expectsOutputToContain('--limit')
        ->assertExitCode(2);
})->with(['abc', '0', '-5', '1.5', '20000']);

it('rejects invalid durations and dates', function () {
    inspect(['requests' => true, 'last' => '5x'])
        ->expectsOutputToContain('Invalid duration')
        ->assertExitCode(2);

    inspect(['requests' => true, 'from' => 'yesterday-ish', 'to' => 'today'])
        ->expectsOutputToContain('Invalid date/time')
        ->assertExitCode(2);
});

it('rejects combining last with explicit ranges', function () {
    inspect(['requests' => true, 'last' => '1h', 'from' => '2026-08-01'])
        ->expectsOutputToContain('--last cannot be combined')
        ->assertExitCode(2);
});

it('rejects inverted from to windows', function () {
    inspect(['requests' => true, 'from' => '2026-08-05', 'to' => '2026-08-01'])
        ->expectsOutputToContain('must not be after')
        ->assertExitCode(2);
});

it('rejects unknown http methods and bad status codes', function () {
    inspect(['http' => true, 'method' => 'FETCH'])
        ->expectsOutputToContain('Unknown HTTP method')
        ->assertExitCode(2);

    inspect(['http' => true, 'status' => 'ok'])
        ->expectsOutputToContain('must be an HTTP status code')
        ->assertExitCode(2);
});

it('rejects unknown fail on checks', function () {
    inspect(['fail-on' => 'world-domination'])
        ->expectsOutputToContain('Unknown --fail-on check')
        ->assertExitCode(2);
});

it('rejects fail on combined with show', function () {
    inspect(['show' => entryUuid(), 'fail-on' => 'exceptions'])
        ->expectsOutputToContain('--fail-on cannot be used together with --show')
        ->assertExitCode(2);
});

it('rejects filters that cannot apply to the selected types', function () {
    inspect(['queries' => true, 'route' => 'orders/*'])
        ->expectsOutputToContain('does not apply to any of the selected')
        ->assertExitCode(2);

    inspect(['jobs' => true, 'failed' => true])
        ->assertExitCode(0);

    inspect(['exceptions' => true, 'method' => 'GET'])
        ->expectsOutputToContain('does not apply to any of the selected')
        ->assertExitCode(2);
});

it('selects gate checks with plural flags', function () {
    $factory = telescopeFactory();
    $factory->add('gate', entryUuid(), null, ['ability' => 'view-orders', 'result' => 'allowed']);
    $factory->persist();

    inspect(['gates' => true])
        ->expectsOutputToContain('Gate Checks · showing 1 of 1')
        ->expectsOutputToContain('view-orders')
        ->assertExitCode(0);
});

it('rejects using json and ndjson together', function () {
    inspect(['json' => true, 'ndjson' => true])
        ->expectsOutputToContain('--json and --ndjson cannot be combined')
        ->assertExitCode(2);
});

it('fails gracefully when telescope tables are missing', function () {
    Schema::connection('testing')->dropIfExists('telescope_entries_tags');
    Schema::connection('testing')->dropIfExists('telescope_entries');

    inspect()
        ->expectsOutputToContain('Telescope storage tables not found')
        ->expectsOutputToContain('php artisan migrate')
        ->assertExitCode(1);
});

it('reports a missing uuid for show mode without crashing', function () {
    inspect(['show' => '00000000-0000-0000-0000-000000000000'])
        ->expectsOutputToContain('No Telescope entry found')
        ->assertExitCode(1);
});

it('searches across tags and content', function () {
    $factory = telescopeFactory();
    $factory->add('cache', entryUuid(), null, ['type' => 'hit', 'key' => 'widgets:dashboard:123']);
    $factory->exception('RuntimeException', 'payment gateway refused the charge');
    $factory->persist();

    // Matches content (exception message).
    inspect(['exceptions' => true, 'search' => 'gateway'])
        ->expectsOutputToContain('showing 1 of 1')
        ->assertExitCode(0);

    // Matches content via cache key.
    inspect(['cache' => true, 'search' => 'widgets:dashboard'])
        ->expectsOutputToContain('showing 1 of 1')
        ->assertExitCode(0);

    // Matches nothing.
    inspect(['exceptions' => true, 'search' => 'no-such-string-anywhere'])
        ->expectsOutputToContain('showing 0 of 1')
        ->assertExitCode(0);
});
