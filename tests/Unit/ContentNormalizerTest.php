<?php

use MrPunyapal\TelescopeInspect\Entries\EntryType;
use MrPunyapal\TelescopeInspect\Normalizers\ContentNormalizer;

function normalizer(int $limit = 1000): ContentNormalizer
{
    return new ContentNormalizer(valueLimit: $limit);
}

it('normalizes request entries', function () {
    $fields = normalizer()->normalize(EntryType::Request, [
        'uri' => 'http://localhost/orders',
        'method' => 'POST',
        'controller_action' => 'OrderController@store',
        'middleware' => ['api', 'auth:sanctum'],
        'headers' => ['x-token' => ['secret']],
        'payload' => ['email' => 'user@example.com'],
        'session' => ['cart' => 3],
        'response_headers' => [],
        'response_status' => 201,
        'response' => '{"id":1}',
        'duration' => 842,
        'memory' => 18.2,
        'ip_address' => '10.0.0.5',
    ]);

    expect($fields['method'])->toBe('POST')
        ->and($fields['uri'])->toBe('http://localhost/orders')
        ->and($fields['controller_action'])->toBe('OrderController@store')
        ->and($fields['middleware'])->toBe(['api', 'auth:sanctum'])
        ->and($fields['response_status'])->toBe(201)
        ->and($fields['duration_ms'])->toBe(842)
        ->and($fields['memory_mb'])->toBe(18.2)
        ->and($fields['ip_address'])->toBe('10.0.0.5');
});

it('normalizes query entries with float durations', function () {
    $fields = normalizer()->normalize(EntryType::Query, [
        'connection' => 'mysql',
        'driver' => 'mysql',
        'bindings' => [7],
        'sql' => 'select * from `orders` where `user_id` = ?',
        'time' => '12.34',
        'slow' => true,
        'file' => '/app/app/Models/Order.php',
        'line' => 55,
        'hash' => 'abc123',
    ]);

    expect($fields['duration_ms'])->toBe(12.34)
        ->and($fields['slow'])->toBeTrue()
        ->and($fields['query_hash'])->toBe('abc123')
        ->and($fields['line'])->toBe(55);
});

it('marks known sensitive fields so presenters can redact them', function () {
    $fields = normalizer()->normalize(EntryType::Request, [
        'method' => 'GET',
        'payload' => ['a' => 1],
        'headers' => ['x' => 'y'],
    ]);

    foreach (['headers', 'payload', 'session', 'response', 'response_headers'] as $field) {
        expect($fields)->toHaveKey($field);
    }
});

it('preserves complex values when the caller opts in', function () {
    $fields = normalizer()->normalize(EntryType::Query, [
        'sql' => 'select * from users',
        'bindings' => ['email@example.com'],
    ], withSensitiveValues: true);

    expect($fields['bindings'])->toBe(['email@example.com']);
});

it('nulls sensitive values by default so analysis never touches them', function () {
    $fields = normalizer()->normalize(EntryType::Query, [
        'sql' => 'select * from users',
        'bindings' => ['email@example.com'],
    ]);

    expect($fields)->toHaveKey('bindings', null);
});

it('truncates long values', function () {
    $fields = normalizer(50)->normalize(EntryType::Log, [
        'level' => 'error',
        'message' => str_repeat('a', 500),
    ]);

    expect(mb_strlen($fields['message']))->toBeLessThanOrEqual(51)
        ->and(str_ends_with($fields['message'], '…'))->toBeTrue();
});

it('normalizes job entries including failures', function () {
    $processed = normalizer()->normalize(EntryType::Job, [
        'name' => 'App\Jobs\SyncOrders',
        'queue' => 'high',
        'connection' => 'redis',
        'status' => 'processed',
        'tries' => 3,
        'data' => ['ids' => [1]],
    ]);

    $failed = normalizer()->normalize(EntryType::Job, [
        'name' => 'App\Jobs\SyncOrders',
        'status' => 'failed',
        'exception' => ['message' => 'SQLSTATE[23000]...', 'trace' => []],
    ]);

    expect($processed['status'])->toBe('processed')
        ->and($processed['exception_message'])->toBeNull()
        ->and($failed['status'])->toBe('failed')
        ->and($failed['exception_message'])->toBe('SQLSTATE[23000]...');
});

it('normalizes every entry type without errors', function (EntryType $type) {
    $fields = normalizer()->normalize($type, []);

    expect(count($fields))->toBeGreaterThan(0);
})->with(fn (): array => array_map(
    fn (EntryType $case): array => [$case],
    EntryType::all(),
));

it('gates dump content behind sensitive values and strips html when included', function () {
    $raw = "<pre class='sf-dump'>array:1 [<span>\"key\"</span> => \"value\"]</pre>";

    // Dumped variables routinely contain secrets: hidden by default.
    $redacted = normalizer()->normalize(EntryType::Dump, ['dump' => $raw]);

    expect($redacted['dump'])->toBeNull();

    $fields = normalizer()->normalize(EntryType::Dump, ['dump' => $raw], withSensitiveValues: true);

    expect($fields['dump'])->not->toContain('<pre')
        ->and($fields['dump'])->toContain('"key"');
});
