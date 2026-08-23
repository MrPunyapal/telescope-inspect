<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

it('handles thousands of entries with bounded output and queries', function () {
    $factory = telescopeFactory();

    // 40 requests, each with 50 queries => 2000 query rows.
    $queriesPerRequest = [];
    for ($r = 0; $r < 40; $r++) {
        $factory->request([
            'uri' => "http://localhost/resource/{$r}",
            'duration' => 100 + $r * 10,
        ]);
        $batchId = $factory->lastBatchId();

        $executions = 20 + $r; // Later requests repeat one query more often.
        $queriesPerRequest[$batchId] = $executions;

        for ($q = 0; $q < $executions - 1; $q++) {
            $factory->query('select * from `rows` where `resource_id` = ?', $batchId, ['time' => 1.5]);
        }
        $factory->query('select count(*) from `rows`', $batchId, ['time' => 3.0]);
    }

    $factory->persist();

    // 40 requests + sum(20..59) queries = 1620 rows.
    expect(telescopeCount('telescope_entries'))->toBe(1620);

    DB::enableQueryLog();
    Artisan::call('telescope:inspect', ['--json' => true, '--requests' => true, '--limit' => 5]);
    $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    // Output stays bounded by --limit...
    expect($payload['items'])->toHaveCount(5)
        // ...while analysis still covers the full window.
        ->and($payload['summary']['analysis']['request']['requests_analyzed'])->toBe(40);

    $queryCountAfterRequests = count(DB::getQueryLog());

    Artisan::call('telescope:inspect', ['--json' => true, '--requests' => true]);
    $fullPayload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($fullPayload['summary']['analysis']['request']['avg_duration_ms'])->toEqual(295.0);

    // The repository must not issue per-row queries: a handful of statements
    // (counts + listing + batch attribution) is enough regardless of size.
    expect(count(DB::getQueryLog()))->toBeLessThan($queryCountAfterRequests + 15);

    DB::disableQueryLog();
});

it('keeps ndjson streaming friendly for large selections', function () {
    $factory = telescopeFactory();

    for ($i = 0; $i < 300; $i++) {
        $factory->add('log', entryUuid(), null, [
            'level' => 'info',
            'message' => "event {$i}",
        ]);
    }

    $factory->persist();

    Artisan::call('telescope:inspect', ['--ndjson' => true, '--logs' => true, '--limit' => 300]);

    // NDJSON uses \n separators by spec, not the platform EOL.
    $lines = explode("\n", trim(Artisan::output()));

    expect($lines)->toHaveCount(300)
        ->and(json_decode($lines[299], true)['message'])->toContain('event');
});
