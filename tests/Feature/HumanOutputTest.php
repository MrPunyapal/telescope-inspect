<?php

use Illuminate\Support\Facades\Artisan;

/**
 * Run the inspect command and return the full rendered output.
 *
 * @param  array<string, bool|int|string|null>  $options
 */
function inspectHuman(array $options = []): string
{
    Artisan::call('telescope:inspect', collect($options)
        ->mapWithKeys(fn ($value, $key): array => ['--'.$key => $value])
        ->filter()
        ->all());

    return Artisan::output();
}

beforeEach(function () {
    $factory = telescopeFactory();

    $factory->request([
        'uri' => 'http://localhost/orders',
        'method' => 'GET',
        'controller_action' => 'OrderController@index',
        'duration' => 842,
    ]);

    for ($i = 0; $i < 3; $i++) {
        $factory->query('select * from `orders`', $factory->lastBatchId(), ['time' => 5 + $i]);
    }

    $factory->exception('RuntimeException', 'The order could not be placed');
    $factory->job('App\Jobs\SyncOrders', 'failed');
    $factory->add('cache', entryUuid(), null, ['type' => 'hit', 'key' => 'orders:1']);
    $factory->add('command', entryUuid(), null, ['command' => 'migrate --force', 'exit_code' => 0]);
    $factory->add('log', entryUuid(), null, ['level' => 'error', 'message' => 'Something went wrong']);
    $factory->add('dump', entryUuid(), null, ['dump' => '<pre>$orders = []</pre>']);
    $factory->persist();
});

it('renders a human readable overview without flags', function () {
    expect(inspectHuman())->toContain('Telescope Inspect')
        ->toContain('Requests')
        ->toContain('Queries')
        ->toContain('Exceptions')
        ->toContain('--requests');
});

it('renders request sections with route aggregates', function () {
    $output = inspectHuman(['requests' => true]);

    expect($output)->toContain('Requests · showing 1 of 1')
        ->toContain('/orders')
        ->toContain('842ms')
        ->toContain('Avg queries');
});

it('renders query analysis sections', function () {
    $output = inspectHuman(['queries' => true]);

    expect($output)->toContain('Slowest queries')
        ->toContain('Most time-consuming query patterns')
        ->toContain('select * from `orders`');
});

it('renders exception signatures', function () {
    $output = inspectHuman(['exceptions' => true]);

    expect($output)->toContain('RuntimeException')
        ->toContain('The order could not be placed')
        ->toContain('distinct signatures');
});

it('renders failed job details', function () {
    $output = inspectHuman(['jobs' => true]);

    expect($output)->toContain('App\Jobs\SyncOrders')
        ->toContain('failed×1');
});

it('lists generic types as tables', function () {
    expect(inspectHuman(['commands' => true]))->toContain('Commands · showing 1 of 1')
        ->toContain('migrate');

    expect(inspectHuman(['logs' => true]))->toContain('Something went wrong');

    expect(inspectHuman(['cache' => true]))->toContain('orders:1');
});

it('truncates long values in tables', function () {
    $factory = telescopeFactory();
    $factory->add('log', entryUuid(), null, [
        'level' => 'info',
        'message' => str_repeat('x', 500),
    ]);
    $factory->persist();

    expect(inspectHuman(['logs' => true]))->toContain('…');
});

it('gates dump detail behind full mode with redaction notice', function () {
    $factory = telescopeFactory();
    $uuid = $factory->add('dump', entryUuid(), null, [
        'dump' => '<pre>secret-ish</pre>',
    ]);
    $factory->persist();

    // Dumped variables can contain anything, so they stay hidden by default.
    $output = inspectHuman(['show' => $uuid]);

    expect($output)->toContain('Telescope Entry')
        ->and($output)->not->toContain('secret-ish')
        ->and(inspectHuman(['show' => $uuid, 'full' => true]))->toContain('secret-ish');
});

it('hides sensitive fields in detail view until full is requested', function () {
    $factory = telescopeFactory();
    $uuid = $factory->request(['payload' => ['password' => 'hunter2']]);
    $factory->persist();

    expect(inspectHuman(['show' => $uuid]))->not->toContain('hunter2')
        ->and(inspectHuman(['show' => $uuid, 'full' => true]))->toContain('hunter2');
});
