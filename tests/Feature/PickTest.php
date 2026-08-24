<?php

it('rejects pick combined with json', function () {
    inspect(['queries' => true, 'pick' => true, 'json' => true])
        ->expectsOutputToContain('--pick cannot be combined with --json or --ndjson.')
        ->assertExitCode(2);
});

it('rejects pick combined with watch', function () {
    inspect(['queries' => true, 'pick' => true, 'watch' => 2])
        ->expectsOutputToContain('--pick cannot be combined with --watch.')
        ->assertExitCode(2);
});

it('rejects pick without a type selection', function () {
    inspect(['pick' => true])
        ->expectsOutputToContain('--pick requires at least one type flag or --batch=<id>.')
        ->assertExitCode(2);
});

it('announces when there is nothing listed to pick', function () {
    inspect(['queries' => true, 'pick' => true])
        ->expectsOutputToContain('Nothing listed to pick from.')
        ->assertExitCode(0);
});

it('falls back gracefully when no interactive terminal can serve the picker', function () {
    $factory = telescopeFactory();
    $factory->query('select * from users where id = ?', entryUuid());
    $factory->persist();

    // In tests (and CI pipes) Prompts cannot open an interactive select:
    // the command must keep its listing, say so, and still exit cleanly.
    inspect(['queries' => true, 'pick' => true])
        ->expectsOutputToContain('Interactive picker unavailable here')
        ->assertExitCode(0);
});
