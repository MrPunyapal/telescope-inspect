<?php

use MrPunyapal\TelescopeInspect\Filters\InspectFilters;
use MrPunyapal\TelescopeInspect\TelescopeInspector;

/**
 * Run the inspector service directly with raw option values.
 *
 * @param  array<string, bool|string|null>  $options
 * @return array<string, array<string, mixed>>
 */
function analyze(array $options = []): array
{
    $filters = InspectFilters::fromOptions(
        array_merge(['limit' => 50], $options)
    );

    return app(TelescopeInspector::class)->inspect($filters)->summariesByType;
}

it('aggregates requests per route with percentiles', function () {
    $factory = telescopeFactory();

    foreach ([100, 200, 300] as $duration) {
        $factory->request(['uri' => 'http://localhost/orders', 'method' => 'GET', 'duration' => $duration]);
    }
    foreach ([1000, 5000] as $duration) {
        $factory->request([
            'uri' => 'http://localhost/reports',
            'method' => 'GET',
            'response_status' => 500,
            'duration' => $duration,
        ]);
    }

    $factory->persist();

    $summary = analyze(['requests' => true])['request'];

    expect($summary['requests_analyzed'])->toBe(5)
        ->and($summary['avg_duration_ms'])->toBe(1320.0)
        ->and($summary['status_distribution'])->toBe(['200' => 3, '500' => 2]);

    /** @var list<array<string, mixed>> $routes */
    /** @var list<array<string, mixed>> $routes */
    $routes = $summary['routes'];

    expect($routes[0]['uri'])->toContain('/reports')
        ->and($routes[0]['requests'])->toBe(2)
        ->and($routes[0]['avg_duration_ms'])->toBe(3000.0)
        // p95 of [1000, 5000] interpolates to 4800
        ->and($routes[0]['p95_duration_ms'])->toBe(4800.0);
});

it('attributes query counts to request batches', function () {
    $factory = telescopeFactory();
    $factory->request(['uri' => 'http://localhost/dashboard']);
    $batchId = $factory->lastBatchId();

    for ($i = 0; $i < 12; $i++) {
        $factory->query('select * from `widgets` where `user_id` = ?', $batchId);
    }

    $factory->persist();

    $summary = analyze(['requests' => true])['request'];

    /** @var array<string, mixed> $route */
    $route = $summary['routes'][0];

    expect($route['avg_queries_per_request'])->toBe(12.0);
});

it('detects likely n plus one patterns without certainty claims', function () {
    $factory = telescopeFactory();
    $factory->request(['uri' => 'http://localhost/orders']);
    $requestBatch = $factory->lastBatchId();

    for ($i = 0; $i < 25; $i++) {
        $factory->query('select * from `items` where `order_id` = ?', $requestBatch);
    }

    // A second batch repeating the query only twice must NOT be flagged.
    $factory->request(['uri' => 'http://localhost/ok']);
    $otherBatch = $factory->lastBatchId();
    for ($i = 0; $i < 2; $i++) {
        $factory->query('select * from `items` where `order_id` = ?', $otherBatch);
    }

    $factory->persist();

    $n1 = analyze(['queries' => true])['query']['likely_n_plus_one'];

    expect($n1)->toHaveCount(1)
        ->and($n1[0]['executions_in_batch'])->toBe(25)
        ->and($n1[0]['batch_id'])->toBe($requestBatch)
        ->and(str_contains(json_encode($n1), 'definitely'))->toBeFalse();
});

it('groups exceptions by signature', function () {
    $factory = telescopeFactory();

    $factory->exception('RuntimeException', 'boom');
    $factory->exception('RuntimeException', 'boom');
    $factory->exception('RuntimeException', 'boom');
    $factory->exception('ValidationException', 'bad input');

    $factory->persist();

    $summary = analyze(['exceptions' => true])['exception'];

    expect($summary['exceptions_analyzed'])->toBe(4)
        ->and($summary['distinct_signatures'])->toBe(2);

    $top = $summary['most_frequent'][0];

    expect($top['class'])->toBe('RuntimeException')
        ->and($top['occurrences'])->toBe(3)
        ->and($top['message'])->toBe('boom')
        ->and($top['last_seen_at'])->toBeString();
});

it('analyzes job outcomes and recurring failures', function () {
    $factory = telescopeFactory();

    $factory->job('App\Jobs\SyncInventory', 'processed');
    $factory->job('App\Jobs\SendInvoice', 'failed');
    $factory->job('App\Jobs\SendInvoice', 'failed');
    $factory->job('App\Jobs\SendInvoice', 'pending');

    $factory->persist();

    $summary = analyze(['jobs' => true])['job'];

    expect($summary['jobs_analyzed'])->toBe(4)
        ->and($summary['status_distribution'])->toBe(['failed' => 2, 'pending' => 1, 'processed' => 1])
        ->and($summary['queues'])->toBe(['default' => 4]);

    $failures = $summary['failed'];

    /** @var array{exception?: string, job?: string, failures?: int} $recurringFirst */
    $recurringFirst = $failures['recurring_failures'][0];

    /** @var array{exception?: string} $latestFailure */
    $latestFailure = $failures['latest_failures'][0];

    expect($failures['total'])->toBe(2)
        ->and($recurringFirst['job'])->toBe('App\Jobs\SendInvoice')
        ->and($recurringFirst['failures'])->toBe(2)
        ->and($latestFailure['exception'])->toBeString();
});
