<?php

use Illuminate\Support\Facades\Artisan;
use MrPunyapal\TelescopeInspect\Entries\EntryType;
use MrPunyapal\TelescopeInspect\Query\EntryRepository;

it('replays a complete batch chronologically', function () {
    $factory = telescopeFactory();
    $factory->request(['uri' => 'http://localhost/orders', 'method' => 'GET', 'duration' => 300]);
    $batchId = $factory->lastBatchId();

    $factory->query('select * from `orders`', $batchId);
    $factory->add('exception', entryUuid(), $batchId, [
        'class' => 'RuntimeException',
        'message' => 'boom in this batch',
        'file' => '/app/app/Http/Orders.php',
        'line' => 10,
    ]);
    $factory->query('select count(*) from `orders`', $batchId);

    $factory->persist();

    // An unrelated batch that must not leak into the replay.
    $other = telescopeFactory();
    $other->request(['uri' => 'http://localhost/other']);
    $other->persist();

    inspect(['batch' => $batchId])
        ->expectsOutputToContain('Telescope Batch')
        ->expectsOutputToContain('/orders')
        ->expectsOutputToContain('boom in this batch')
        ->doesntExpectOutputToContain('/other')
        ->assertExitCode(0);
});

it('rejects narrowing filters alongside batch mode', function () {
    inspect(['batch' => entryUuid(), 'requests' => true])
        ->expectsOutputToContain('--batch shows the complete batch and cannot be combined with: --requests.')
        ->assertExitCode(2);

    inspect(['batch' => entryUuid(), 'last' => '1h'])
        ->assertExitCode(2);
});

it('emits batch entries as json items', function () {
    $factory = telescopeFactory();
    $factory->request(['uri' => 'http://localhost/api/ping']);
    $batchId = $factory->lastBatchId();
    $factory->query('select 1', $batchId);
    $factory->persist();

    Artisan::call('telescope:inspect', ['--json' => true, '--batch' => $batchId]);

    /** @var array<string, mixed> $payload */
    $payload = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);

    expect(count($payload['items']))->toBe(2)
        ->and($payload['filters']['batch_id'])->toBe($batchId)
        ->and($payload['summary']['total_entries_in_window'])->toBe(2)
        // Chronological: the request is recorded before its queries.
        ->and($payload['items'][0]['type'])->toBe('request');
});

it('rejects watch without type flags', function () {
    inspect(['watch' => true])
        ->expectsOutputToContain('--watch requires at least one type flag')
        ->assertExitCode(2);
});

it('finds entries recorded after a sequence id for watch mode', function () {
    $repository = app(EntryRepository::class);

    expect($repository->latestSequence())->toBe(0);

    $factory = telescopeFactory();
    for ($i = 0; $i < 3; $i++) {
        $factory->add('log', entryUuid(), null, ['level' => 'info', 'message' => "m{$i}"]);
    }
    $factory->persist();

    $highWater = $repository->latestSequence();
    expect($highWater)->toBe(3);

    $new = telescopeFactory();
    $new->add('log', entryUuid(), null, ['level' => 'info', 'message' => 'later']);
    $new->add('cache', entryUuid(), null, ['type' => 'hit', 'key' => 'k']);
    $new->persist();

    $logs = $repository->findSinceSequence($highWater, [EntryType::Log]);

    expect($logs)->toHaveCount(1)
        ->and($logs[0]->field('message'))->toBe('later')
        ->and($logs[0]->sequence)->toBeGreaterThan($highWater);
});
