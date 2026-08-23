<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use MrPunyapal\TelescopeInspect\Filters\InspectFilters;
use MrPunyapal\TelescopeInspect\Filters\InvalidFilter;
use MrPunyapal\TelescopeInspect\Normalizers\ContentNormalizer;
use MrPunyapal\TelescopeInspect\Query\EntryRepository;
use MrPunyapal\TelescopeInspect\TelescopeInspector;

beforeEach(function () {
    Carbon::setTestNow('2026-08-22 12:00:00');
});

// ---------------------------------------------------------------- ordering

it('groups json items by type in canonical order across formats', function () {
    $factory = telescopeFactory();
    // Sequence order deliberately differs from the canonical type order.
    $factory->exception('RuntimeException', 'boom');
    $factory->request(['uri' => 'http://localhost/a']);
    $factory->query('select 1', entryUuid());
    $factory->persist();

    [, $payload] = inspectJsonWithExit([
        'requests' => true, 'queries' => true, 'exceptions' => true,
    ]);

    /** @var list<array<string, mixed>> $items */
    $items = $payload['items'];

    expect(collect($items)->pluck('type')->all())
        ->toBe(['request', 'query', 'exception']);

    Artisan::call('telescope:inspect', [
        '--ndjson' => true, '--requests' => true, '--queries' => true, '--exceptions' => true,
    ]);

    $lines = collect(preg_split('/\r\n|\r|\n/', trim(Artisan::output())))
        ->filter()
        ->values();

    expect($lines->map(fn (string $line): string => (string) json_decode($line, true)['type'])->all())
        ->toBe(['request', 'query', 'exception']);
});

// ------------------------------------------------------- truncation truth

it('reports scan truncation false when the window was fully scanned', function () {
    $factory = telescopeFactory();

    foreach ([1, 2, 3] as $i) {
        $factory->request(['uri' => "http://localhost/orders/{$i}", 'duration' => 100]);
        $factory->add('cache', entryUuid(), null, ['type' => 'hit', 'key' => "k{$i}"]);
    }
    $factory->persist();

    [, $payload] = inspectJsonWithExit(['requests' => true, 'route' => '*orders*']);

    // Content filters were active and far fewer rows exist than scan_limit:
    // nothing was withheld.
    expect($payload['summary']['scan']['truncated'])->toBeFalse()
        ->and($payload['summary']['items_returned']['request'])->toBe(3);
});

it('reports scan truncation true only when rows were actually dropped', function () {
    // Rebind with a small ceiling directly (the provider clamps to >= 100).
    $repository = new EntryRepository(
        new ContentNormalizer,
        scanLimit: 2,
    );
    app()->instance(EntryRepository::class, $repository);
    app()->instance(TelescopeInspector::class, new TelescopeInspector($repository));

    $factory = telescopeFactory();

    foreach (range(1, 5) as $i) {
        $factory->request(['uri' => "http://localhost/r{$i}", 'duration' => 10]);
    }
    $factory->persist();

    [, $payload] = inspectJsonWithExit(['requests' => true, 'limit' => 5]);

    expect($payload['summary']['scan']['truncated'])->toBeTrue()
        ->and($payload['summary']['items_returned']['request'])->toBe(2)
        ->and($payload['summary']['analysis']['request']['rows_scanned'])->toBeLessThanOrEqual(2);
});

it('exposes rows_scanned for every analysis summary', function () {
    $factory = telescopeFactory();
    $batch = entryUuid();
    $factory->request();
    $factory->query('select 1', $factory->lastBatchId());
    $factory->exception('RuntimeException', 'boom');
    $factory->job('App\Jobs\X', 'failed');
    $factory->persist();

    [, $payload] = inspectJsonWithExit([
        'requests' => true, 'queries' => true, 'exceptions' => true, 'jobs' => true,
    ]);

    expect($payload['summary']['analysis']['request'])->toHaveKey('rows_scanned')
        ->and($payload['summary']['analysis']['query'])->toHaveKey('rows_scanned')
        ->and($payload['summary']['analysis']['exception'])->toHaveKey('rows_scanned')
        ->and($payload['summary']['analysis']['job'])->toHaveKey('rows_scanned')
        ->and($payload['filters'])->not->toHaveKey('show_uuid');
});

// ------------------------------------------------------- not-found exits

it('reports missing uuids with exit code 1 in every output mode', function () {
    $missing = entryUuid();

    inspect(['show' => $missing])->assertExitCode(1);
    inspect(['show' => $missing, 'json' => true])
        ->assertExitCode(1);
    inspect(['show' => $missing, 'ndjson' => true])
        ->assertExitCode(1);

    // In machine modes the diagnostic is prose, never a JSON-looking
    // document; real stream separation is proven by the process tests.
    Artisan::call('telescope:inspect', ['--show' => $missing, '--json' => true]);

    expect(str_contains(Artisan::output(), '"schema_version"'))->toBeFalse();
});

it('reports missing batch ids with exit code 1 in every output mode', function () {
    inspect(['batch' => 'no-such-batch-id'])->assertExitCode(1);
    inspect(['batch' => 'no-such-batch-id', 'json' => true])->assertExitCode(1);
});

// ------------------------------------------------------ redaction states

it('removes redacted keys entirely while keeping genuinely absent fields null', function () {
    $factory = telescopeFactory();
    // No controller_action recorded: present-but-null after normalization.
    $factory->request(['controller_action' => null, 'payload' => ['token' => 'abc']]);
    $factory->persist();

    [, $payload] = inspectJsonWithExit(['requests' => true]);

    $item = $payload['items'][0];

    expect(array_key_exists('payload', $item))->toBeFalse()
        ->and(array_key_exists('headers', $item))->toBeFalse()
        ->and(array_key_exists('controller_action', $item))->toBeTrue()
        ->and($item['controller_action'])->toBeNull();
});

it('keeps batch items stripped of sensitive keys even though batch hydration reads them', function () {
    $factory = telescopeFactory();
    $uuid = $factory->request(['payload' => ['password' => 'hunter2']]);
    $factory->persist();

    [, $payload] = inspectJsonWithExit(['batch' => $factory->lastBatchId()]);

    foreach ($payload['items'] as $item) {
        expect(json_encode($payload))->not->toContain('hunter2')
            ->and($item)->not->toHaveKey('payload');
    }

    // The lifecycle can still be replayed in full when explicitly asked.
    [, $full] = inspectJsonWithExit(['batch' => $factory->lastBatchId(), 'full' => true]);

    expect(json_encode($full))->toContain('hunter2');
});

it('honors the global redact_sensitive config kill-switch', function () {
    $factory = telescopeFactory();
    $factory->request(['payload' => ['secret' => 'hunter2']]);
    $factory->persist();

    config(['telescope-inspect.redact_sensitive' => false]);

    [, $payload] = inspectJsonWithExit(['requests' => true]);

    expect(json_encode($payload))->toContain('hunter2')
        ->and($payload['filters']['full'])->toBeTrue();
});

// ------------------------------------------------------------ fail-on exits

it('returns exit code 3 with valid stdout in json and ndjson modes on violations', function () {
    $factory = telescopeFactory();
    $factory->job('App\Jobs\SyncOrders', 'failed');
    $factory->persist();

    [$exit, $payload] = inspectJsonWithExit(['fail-on' => 'failed-jobs']);

    expect($exit)->toBe(3)
        ->and($payload['violations'])->toBe(['failed-jobs']);

    $exit = Artisan::call('telescope:inspect', ['--ndjson' => true, '--jobs' => true, '--fail-on' => 'failed-jobs']);

    // In-process runs buffer both streams together, so the stderr violation
    // note appears here; every remaining line must still be valid JSON.
    $lines = collect(preg_split('/\r\n|\r|\n/', trim(Artisan::output())))
        ->filter()
        ->reject(fn (string $line): bool => str_starts_with($line, 'Issues found'))
        ->values();

    foreach ($lines as $line) {
        json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    }

    expect($exit)->toBe(3);
});

// ------------------------------------------------------------ public API

it('builds filters from semantic keys for programmatic use', function () {
    $filters = InspectFilters::fromArray([
        'types' => ['request', 'queries'],
        'last' => '1h',
        'min_duration_ms' => 250.5,
        'statuses' => [500],
        'methods' => ['post'],
        'fail_on' => ['slow-requests'],
        'limit' => 25,
    ]);

    expect($filters->types)->toHaveCount(2)
        ->and($filters->lastRaw)->toBe('1h')
        ->and($filters->minDurationMs)->toBe(250.5)
        ->and($filters->statuses)->toBe([500])
        ->and($filters->methods)->toBe(['POST'])
        ->and($filters->failOn)->toBe(['slow-requests'])
        ->and($filters->limit)->toBe(25);
});

it('rejects unknown values through fromArray exactly like fromOptions', function () {
    expect(fn (): InspectFilters => InspectFilters::fromArray(['types' => ['request'], 'last' => 'nope']))
        ->toThrow(InvalidFilter::class);
});

it('rejects a zero min duration combined with slow fail on checks', function () {
    expect(fn (): InspectFilters => InspectFilters::fromArray([
        'types' => ['queries'],
        'min_duration_ms' => 0,
        'fail_on' => ['slow-queries'],
    ]))->toThrow(InvalidFilter::class, 'mark every entry as slow');
});

// --------------------------------------------- batch cap and bus fallback

it('propagates truncation state for oversized batches', function () {
    $repository = new EntryRepository(
        new ContentNormalizer,
        scanLimit: 2,
    );
    app()->instance(EntryRepository::class, $repository);
    app()->instance(TelescopeInspector::class, new TelescopeInspector($repository));

    $factory = telescopeFactory();
    $shared = entryUuid();

    foreach (range(1, 4) as $i) {
        $factory->add('log', entryUuid(), $shared, ['level' => 'info', 'message' => "m{$i}"]);
    }
    $factory->persist();

    [, $payload] = inspectJsonWithExit(['batch' => $shared]);

    expect($payload['summary']['total_entries_in_window'])->toBe(2)
        ->and($payload['summary']['scan']['truncated'])->toBeTrue();
});
