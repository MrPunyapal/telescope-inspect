<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

/**
 * Run the inspect command with JSON output and strict-parse the envelope.
 *
 * @param  array<string, bool|int|string|null>  $options
 * @return array<string, mixed>
 */
function inspectJson(array $options = []): array
{
    Artisan::call('telescope:inspect', array_merge(['--json' => true], collect($options)
        ->mapWithKeys(fn ($value, $key): array => ['--'.$key => $value])
        ->filter()
        ->all()));

    $output = Artisan::output();

    // Strict parse: any stray output breaks this immediately.
    /** @var array<string, mixed> $decoded */
    $decoded = json_decode(trim($output), true, 512, JSON_THROW_ON_ERROR);

    return $decoded;
}

beforeEach(function () {
    Carbon::setTestNow('2026-08-22 12:00:00');

    $factory = telescopeFactory();
    $factory->request([
        'uri' => 'http://localhost/orders',
        'method' => 'GET',
        'duration' => 900,
        'response_status' => 200,
        'payload' => ['secret' => 'hunter2'],
    ]);
    $factory->query('select * from `orders`', $factory->lastBatchId(), ['time' => 12.5]);
    $factory->exception('RuntimeException', 'boom');
    $factory->job('App\Jobs\SyncOrders', 'failed');
    $factory->persist();
});

it('emits a stable json envelope', function () {
    $payload = inspectJson(['requests' => true]);

    expect(array_keys($payload))->toBe([
        'schema_version',
        'command',
        'generated_at',
        'filters',
        'summary',
        'violations',
        'items',
    ])
        ->and($payload['schema_version'])->toBe('1.0')
        ->and($payload['command'])->toBe('telescope:inspect')
        ->and($payload['generated_at'])->toBe('2026-08-22T12:00:00.000000Z')
        ->and($payload['summary']['total_entries_in_window'])->toBe(4)
        ->and($payload['violations'])->toBe([])
        ->and($payload['summary']['scan'])->toBe(['limit' => 5000, 'truncated' => false])
        ->and($payload['summary']['analysis_scoped_to_filters'])->toBeFalse()
        ->and($payload['filters']['full'])->toBeFalse()
        ->and($payload['filters']['fail_on'])->toBe([]);
});

it('emits normalized items with documented fields', function () {
    $payload = inspectJson(['queries' => true, 'limit' => 5]);

    expect($payload['items'])->toHaveCount(1);

    $item = $payload['items'][0];

    expect($item['type'])->toBe('query')
        ->and($item['sql'])->toBe('select * from `orders`')
        ->and($item['connection'])->toBe('sqlite')
        ->and($item['duration_ms'])->toBe(12.5)
        ->and($item['uuid'])->toBeString()
        ->and($item['batch_id'])->toBeString()
        ->and($item['created_at'])->toBeString();
});

it('never leaks sensitive values into json output', function () {
    $payload = inspectJson(['requests' => true]);

    foreach ($payload['items'] as $item) {
        expect(json_encode($payload))->not->toContain('hunter2')
            ->and($item['payload'] ?? null)->toBeNull();
    }
});

it('includes sensitive values only with full mode', function () {
    $payload = inspectJson(['requests' => true, 'full' => true]);

    expect(json_encode($payload))->toContain('hunter2')
        ->and($payload['items'][0]['payload'])->toBe(['secret' => 'hunter2']);
});

it('produces an empty but valid envelope when nothing matches', function () {
    $payload = inspectJson(['views' => true]);

    expect($payload['items'])->toBe([])
        ->and($payload['summary']['total_entries_in_window'])->toBe(4)
        ->and($payload['summary']['entries_by_type']['view'] ?? null)->toBeNull();
});

it('combines multiple types into a single items stream with summaries', function () {
    $payload = inspectJson([
        'requests' => true,
        'queries' => true,
        'exceptions' => true,
        'jobs' => true,
        'last' => '1h',
        'limit' => 100,
    ]);

    /** @var list<array<string, mixed>> $items */
    $items = $payload['items'];

    $types = collect($items)->pluck('type');

    expect($types->unique()->sort()->values()->all())
        ->toBe(['exception', 'job', 'query', 'request'])
        // Each entry must appear exactly once — no per-type duplication.
        ->and($types->count())->toBe(4)
        ->and($payload['summary']['items_returned']['request'])->toBe(1)
        ->and($payload['summary']['items_returned']['query'])->toBe(1)
        ->and($payload['summary']['items_returned']['exception'])->toBe(1)
        ->and($payload['summary']['items_returned']['job'])->toBe(1)
        ->and($payload['summary']['analysis'])->toHaveKeys(['request', 'query', 'exception', 'job'])
        ->and($payload['filters']['types'])->toBe(['request', 'query', 'exception', 'job'])
        ->and($payload['filters']['last'])->toBe('1h')
        ->and($payload['filters']['resolved_window_utc']['from'])->toBeString();
});

it('reports fail on violations structurally without polluting stdout', function () {
    Artisan::call('telescope:inspect', [
        '--json' => true,
        '--exceptions' => true,
        '--fail-on' => 'exceptions',
    ]);

    $payload = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);

    // The document stays parseable; violations are data, not prose.
    expect($payload['violations'])->toBe(['exceptions']);
});

it('emits ndjson with one compact object per line', function () {
    Artisan::call('telescope:inspect', ['--ndjson' => true, '--requests' => true]);

    $lines = explode(PHP_EOL, trim(Artisan::output()));

    expect($lines)->toHaveCount(1);

    foreach ($lines as $line) {
        $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);

        expect($decoded)->toHaveKey('uuid')
            ->toHaveKey('type')
            ->and(json_encode($decoded))->not->toContain("\n");
    }
});

it('json output contains no ansi escape codes or decorations', function () {
    Artisan::call('telescope:inspect', ['--json' => true, '--exceptions' => true]);

    $output = Artisan::output();

    expect(preg_match('/\x1b\[[0-9;]*m/', $output))->toBe(0)
        ->and(str_starts_with(trim($output), '{'))->toBeTrue();
});

it('exposes the detailed single entry envelope for --show', function () {
    $factory = telescopeFactory();
    $uuid = $factory->add('dump', entryUuid(), null, [
        'dump' => '<pre>hello</pre>',
    ]);
    $factory->persist();

    $payload = inspectJson(['show' => $uuid]);

    expect($payload['entry']['type'])->toBe('dump')
        ->and($payload['entry']['uuid'])->toBe($uuid)
        ->and($payload['entry']['dump'])->toContain('hello');
});
