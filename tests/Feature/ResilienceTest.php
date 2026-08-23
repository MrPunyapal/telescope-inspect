<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use MrPunyapal\TelescopeInspect\Filters\InspectFilters;
use MrPunyapal\TelescopeInspect\TelescopeInspector;

it('keeps json stdout pure when search runs without a window', function () {
    $factory = telescopeFactory();
    $factory->exception('RuntimeException', 'boom about searching');
    $factory->persist();

    Artisan::call('telescope:inspect', [
        '--json' => true,
        '--exceptions' => true,
        '--search' => 'boom',
    ]);

    // Strict parse fails if any prose or warning preceded the document.
    $payload = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);

    expect($payload['items'])->toHaveCount(1);
});

it('keeps ndjson stdout pure when full is requested', function () {
    $factory = telescopeFactory();
    $factory->request(['uri' => 'http://localhost/full', 'payload' => ['k' => 'v']]);
    $factory->persist();

    Artisan::call('telescope:inspect', [
        '--ndjson' => true,
        '--requests' => true,
        '--full' => true,
    ]);

    foreach (explode("\n", trim(Artisan::output())) as $line) {
        json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    }

    expect(true)->toBeTrue();
});

it('survives corrupt content and garbage timestamps and unknown types', function () {
    DB::table('telescope_entries')->insert([
        [
            'uuid' => entryUuid(),
            'batch_id' => entryUuid(),
            'family_hash' => null,
            'should_display_on_index' => true,
            'type' => 'query',
            'content' => '{"sql": "select 1", "time": "5.00"', // truncated JSON
            'created_at' => now()->format('Y-m-d H:i:s'),
        ],
        [
            'uuid' => entryUuid(),
            'batch_id' => entryUuid(),
            'family_hash' => null,
            'should_display_on_index' => true,
            'type' => 'query',
            'content' => '{"sql": "select 2", "time": "6.00"}',
            'created_at' => 'not-a-timestamp',
        ],
        [
            'uuid' => entryUuid(),
            'batch_id' => entryUuid(),
            'family_hash' => null,
            'should_display_on_index' => true,
            'type' => 'brand_new_future_type',
            'content' => '{"whatever": true}',
            'created_at' => now()->format('Y-m-d H:i:s'),
        ],
    ]);

    inspect(['queries' => true])
        ->assertExitCode(0);

    inspect()
        ->expectsOutputToContain('Queries')
        ->assertExitCode(0);
});

it('distinguishes starved types from empty windows', function () {
    $factory = telescopeFactory();

    // Cache goes in first so the five requests win the newest-3 race.
    $factory->add('cache', entryUuid(), null, ['type' => 'hit', 'key' => 'k']);
    for ($i = 0; $i < 5; $i++) {
        $factory->request(['uri' => "http://localhost/r{$i}"]);
    }
    $factory->persist();

    inspect(['requests' => true, 'cache' => true, 'limit' => 3])
        ->expectsOutputToContain('Cache exist in the window but none are among the newest 3')
        ->assertExitCode(0);
});

it('caps latest job failures at ten', function () {
    $factory = telescopeFactory();

    for ($i = 0; $i < 14; $i++) {
        $factory->job("App\Jobs\Job{$i}", 'failed');
    }

    $factory->persist();

    $filters = InspectFilters::fromOptions(['jobs' => true]);
    $summary = app(TelescopeInspector::class)->inspect($filters)->summariesByType['job'];

    expect(count($summary['failed']['latest_failures']))->toBe(10)
        ->and($summary['failed']['recurring_failures'])->toHaveCount(10);
});
