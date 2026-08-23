<?php

use MrPunyapal\TelescopeInspect\Tests\Fixtures\EntryFactory;

function factoryWithIssues(): EntryFactory
{
    $factory = telescopeFactory();

    $factory->exception('RuntimeException', 'boom');
    $factory->request(['uri' => 'http://localhost/slow', 'duration' => 2500]);
    $factory->job('App\Jobs\Broken', 'failed');
    $factory->query('select * from `slow_table`', entryUuid(), ['time' => 900]);

    $factory->persist();

    return $factory;
}

it('exits successfully by default even when issues exist', function () {
    factoryWithIssues();

    inspect()->assertExitCode(0);
});

it('fails on exceptions when requested', function () {
    factoryWithIssues();

    inspect(['fail-on' => 'exceptions'])
        ->expectsOutputToContain('Issues found: exceptions')
        ->assertExitCode(3);
});

it('fails on failed jobs', function () {
    factoryWithIssues();

    inspect(['jobs' => true, 'fail-on' => 'failed-jobs'])->assertExitCode(3);
});

it('detects failures even when the matching type flag is absent', function () {
    // Only requests selected, but --fail-on=failed-jobs must still analyze jobs.
    factoryWithIssues();

    inspect(['requests' => true, 'fail-on' => 'failed-jobs'])->assertExitCode(3);
});

it('fails on slow requests using the configured threshold', function () {
    factoryWithIssues();
    config(['telescope-inspect.slow_threshold_ms' => 2000]);

    inspect(['requests' => true, 'fail-on' => 'slow-requests'])->assertExitCode(3);
});

it('respects min duration for slow checks', function () {
    $factory = telescopeFactory();
    $factory->query('select * from `medium_table`', entryUuid(), ['time' => 900]);
    $factory->persist();
    config(['telescope-inspect.slow_threshold_ms' => 10000]);

    // Threshold above every recorded duration: passes.
    inspect(['queries' => true, 'fail-on' => 'slow-queries'])->assertExitCode(0);

    // Explicit --min-duration lowers it below 900ms.
    inspect(['queries' => true, 'min-duration' => '500', 'fail-on' => 'slow-queries'])
        ->expectsOutputToContain('Issues found: slow-queries')
        ->assertExitCode(3);
});

it('passes fail on checks when the window is healthy', function () {
    telescopeFactory()->request(['uri' => 'http://localhost/healthy', 'duration' => 40]);
    telescopeFactory()->persist();

    inspect(['fail-on' => 'exceptions,failed-jobs,slow-requests,slow-queries'])
        ->assertExitCode(0);
});
