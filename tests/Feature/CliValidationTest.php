<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

/**
 * Validation guards added during hardening: combinations that are rejected
 * outright because they could only mislead.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-08-22 12:00:00');
});

it('rejects fail-on combined with batch or watch', function () {
    inspect(['fail-on' => 'exceptions', 'batch' => 'abc'])->assertExitCode(2);
    inspect(['fail-on' => 'exceptions', 'requests' => true, 'watch' => true])->assertExitCode(2);
});

it('rejects show combined with watch', function () {
    inspect(['show' => entryUuid(), 'watch' => true])->assertExitCode(2);
});

it('rejects content filters alongside watch instead of silently ignoring them', function () {
    inspect(['requests' => true, 'watch' => true, 'min-duration' => 500])->assertExitCode(2);
    inspect(['jobs' => true, 'watch' => true, 'failed' => true])->assertExitCode(2);
    inspect(['requests' => true, 'watch' => true, 'route' => '*orders*'])->assertExitCode(2);
    inspect(['queries' => true, 'watch' => true, 'search' => 'x'])->assertExitCode(2);

    // Type flags and windows remain legal with watch.
    inspect(['requests' => true, 'last' => '15m', 'limit' => 5])
        ->assertExitCode(0);
});

it('rejects non numeric watch intervals', function (string $value) {
    inspect(['requests' => true, 'watch' => $value])->assertExitCode(2);
})->with(['abc', '0', '-3', '1.5']);

it('rejects status codes below 100', function () {
    inspect(['requests' => true, 'status' => '0'])->assertExitCode(2);
    inspect(['requests' => true, 'status' => '99'])->assertExitCode(2);
});

it('rejects limit combined with batch mode', function () {
    $factory = telescopeFactory();
    $uuid = $factory->request();
    $factory->persist();

    inspect(['batch' => $factory->lastBatchId(), 'limit' => 10])->assertExitCode(2);
});

it('accepts ndjson for a full batch replay', function () {
    $factory = telescopeFactory();
    $factory->request(['uri' => 'http://localhost/orders']);
    $factory->query('select * from orders', $factory->lastBatchId());
    $factory->persist();

    $exit = Artisan::call('telescope:inspect', ['--ndjson' => true, '--batch' => $factory->lastBatchId()]);

    $lines = collect(preg_split('/\r\n|\r|\n/', trim(Artisan::output())))->filter()->values();

    expect($lines)->toHaveCount(2)
        ->and($exit)->toBe(0);
});
