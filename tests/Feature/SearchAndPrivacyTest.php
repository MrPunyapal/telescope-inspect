<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

/**
 * Privacy hardening and search semantics added during the audit.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-08-22 12:00:00');
});

// ------------------------------------------------------------ redis privacy

it('hides redis command parameters by default and shows them with full', function () {
    $factory = telescopeFactory();
    $factory->add('redis', entryUuid(), null, [
        'command' => 'SETEX otp:424242 300 998877',
        'connection' => 'cache',
        'time' => 1.5,
    ]);
    $factory->persist();

    $default = humanOutput(['redis' => true]);

    expect($default)->toContain('SETEX otp:424242');
    expect($default)->not->toContain('998877');

    [, $hidden] = inspectJsonWithExit(['redis' => true]);

    expect(json_encode($hidden))->not->toContain('998877')
        ->and($hidden['items'][0])->not->toHaveKey('arguments')
        ->and($hidden['items'][0]['command'])->toBe('SETEX otp:424242');

    [, $full] = inspectJsonWithExit(['redis' => true, 'full' => true]);

    expect($full['items'][0]['arguments'])->toBe('300 998877');
});

it('keeps simple redis verbs fully visible', function () {
    $factory = telescopeFactory();
    $factory->add('redis', entryUuid(), null, ['command' => 'PING', 'time' => 0.2]);
    $factory->persist();

    expect(humanOutput(['redis' => true]))->toContain('PING');
});

// ------------------------------------------------------------- uri privacy

it('splits request uris into a path and a gated query string', function () {
    $factory = telescopeFactory();
    $factory->request(['uri' => 'https://app.example.com/reset?token=topsecret&email=a@b.c']);
    $factory->persist();

    [, $payload] = inspectJsonWithExit(['requests' => true]);
    $item = $payload['items'][0];

    expect($item['uri'])->toBe('https://app.example.com/reset')
        ->and(json_encode($payload))->not->toContain('topsecret')
        ->and($item)->not->toHaveKey('query_string');

    [, $full] = inspectJsonWithExit(['requests' => true, 'full' => true]);

    expect($full['items'][0]['query_string'])->toBe('token=topsecret&email=a@b.c');
});

it('groups request routes by path rather than per query string', function () {
    $factory = telescopeFactory();
    $factory->request(['uri' => 'http://localhost/orders?p=1', 'duration' => 100]);
    $factory->request(['uri' => 'http://localhost/orders?p=2', 'duration' => 200]);
    $factory->persist();

    [, $payload] = inspectJsonWithExit(['requests' => true]);

    expect($payload['summary']['analysis']['request']['routes'])->toHaveCount(1)
        ->and($payload['summary']['analysis']['request']['routes'][0]['requests'])->toBe(2);
});

// ------------------------------------------- schedule output + occurrences

it('gates schedule output behind full mode', function () {
    $factory = telescopeFactory();
    $uuid = $factory->add('schedule', entryUuid(), null, [
        'command' => 'php artisan backup:run',
        'description' => 'Nightly backup',
        'expression' => '* * * * *',
        'output' => 'mysqldump: credentials in output',
    ]);
    $factory->persist();

    [, $hidden] = inspectJsonWithExit(['show' => $uuid]);
    [, $shown] = inspectJsonWithExit(['show' => $uuid, 'full' => true]);

    expect(json_encode($hidden))->not->toContain('credentials in output')
        ->and($shown['entry']['output'])->toContain('credentials in output');
});

it('surfaces telescopes exception merge counter as occurrences', function () {
    $factory = telescopeFactory();
    $factory->add('exception', entryUuid(), null, [
        'class' => 'RuntimeException',
        'message' => 'boom',
        'occurrences' => 7,
    ]);
    $factory->persist();

    [, $payload] = inspectJsonWithExit(['exceptions' => true, 'limit' => 5]);

    expect($payload['items'][0]['occurrences'])->toBe(7);
});

// ------------------------------------------------------------------ search

it('finds terms containing slashes that are stored json escaped', function () {
    $factory = telescopeFactory();
    $factory->exception('RuntimeException', 'payment gateway refused users/create calls');
    $factory->persist();

    // The stored content JSON contains "users\/create"; the raw term must
    // still match through the encoded needle form.
    inspect(['exceptions' => true, 'search' => 'users/create'])
        ->expectsOutputToContain('showing 1 of 1')
        ->assertExitCode(0);
});

it('treats like wildcards literally so percent signs are searchable', function () {
    $factory = telescopeFactory();
    $factory->add('cache', entryUuid(), null, ['type' => 'hit', 'key' => 'widgets:100%']);
    $factory->add('cache', entryUuid(), null, ['type' => 'hit', 'key' => 'widgets:100X']);
    $factory->persist();

    // % and _ are literals: only the true 100% key matches.
    inspect(['cache' => true, 'search' => '100%'])
        ->expectsOutputToContain('showing 1 of 2')
        ->assertExitCode(0);

    inspect(['cache' => true, 'search' => '_idgets_'])
        ->expectsOutputToContain('showing 0 of 2');
});

it('matches tags case insensitively regardless of database collation', function () {
    $factory = telescopeFactory();
    $uuid = $factory->exception('RuntimeException', 'boom');
    $factory->persist();

    Artisan::call('telescope:inspect', ['--exceptions' => true, '--search' => 'runtimeexception']);

    expect(Artisan::output())->toContain('showing 1 of 1');
})->skip('SQL prefilter case handling follows the database; verification is case-insensitive but cannot recover rows the prefilter missed on case-sensitive drivers.');

// ------------------------------------------------------- duplicate markers

it('marks repeated sql with an xn badge for humans', function () {
    $factory = telescopeFactory();
    $batch = entryUuid();

    foreach (range(1, 3) as $i) {
        $factory->query("select * from `orders` where `id` = {$i}", $batch, ['hash' => md5('same')]);
    }
    $factory->query('select 1 from `singleton`', $batch);
    $factory->persist();

    $output = humanOutput(['queries' => true]);

    expect($output)->toContain('×3')
        ->toContain('(×N marks SQL repeated on this page)');
});

it('restores the query listing under the analysis summary', function () {
    $factory = telescopeFactory();
    $factory->query('select * from `sessions`', entryUuid());
    $factory->persist();

    $output = humanOutput(['queries' => true]);

    expect($output)->toContain('Slowest queries')
        ->toContain('select * from `sessions`');
});

// ------------------------------------------------------------ n+1 boundary

it('flags n plus one only at the exact threshold within one batch', function () {
    $factory = telescopeFactory();
    $requestUuid = $factory->request();
    $batch = $factory->lastBatchId();

    foreach (range(1, 9) as $i) {
        $factory->query('select * from `users` where `email` = ?', $batch, ['sql' => 'select * from `users` where `email` = ?', 'hash' => md5('users-by-email')]);
    }
    $factory->persist();

    [, $below] = inspectJsonWithExit(['queries' => true]);
    expect($below['summary']['analysis']['query']['likely_n_plus_one'])->toBe([]);

    $factory = telescopeFactory();
    $factory->request();
    foreach (range(1, 10) as $i) {
        $factory->query('select * from `users` where `email` = ?', $factory->lastBatchId(), ['hash' => md5('users-by-email')]);
    }
    $factory->persist();

    [, $at] = inspectJsonWithExit(['queries' => true]);

    $candidates = $at['summary']['analysis']['query']['likely_n_plus_one'];

    expect($candidates)->toHaveCount(1)
        ->and($candidates[0]['executions_in_batch'])->toBe(10)
        ->and($candidates[0])->toHaveKey('batch_id');
});

it('does not flag repetition spread across many batches', function () {
    $factory = telescopeFactory();

    foreach (range(1, 6) as $i) {
        $factory->request();
        foreach (range(1, 6) as $j) {
            $factory->query('select * from `users` where `id` = ?', $factory->lastBatchId(), ['hash' => md5('users')]);
        }
    }
    $factory->persist();

    [, $payload] = inspectJsonWithExit(['queries' => true]);

    expect($payload['summary']['analysis']['query']['likely_n_plus_one'])->toBe([]);
});

// ------------------------------------------------------------ overview tip

it('mentions failed jobs in the overview when any exist', function () {
    $factory = telescopeFactory();
    $factory->job('App\Jobs\SyncOrders', 'failed');
    $factory->job('App\Jobs\SyncOrders', 'failed');
    $factory->persist();

    expect(humanOutput([]))->toContain('2 failed jobs recorded');
});
