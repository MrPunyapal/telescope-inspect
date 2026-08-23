<?php

use Illuminate\Support\Carbon;

beforeEach(function () {
    $factory = telescopeFactory();

    // Three orders requests, one slow checkout, one 500.
    $factory->request(['uri' => 'http://localhost/orders', 'method' => 'GET', 'duration' => 100]);
    $factory->request(['uri' => 'http://localhost/orders', 'method' => 'GET', 'duration' => 300]);
    $factory->request(['uri' => 'http://localhost/orders', 'method' => 'GET', 'duration' => 200]);
    $factory->request([
        'uri' => 'http://localhost/checkout',
        'method' => 'POST',
        'controller_action' => 'CheckoutController@store',
        'response_status' => 302,
        'duration' => 4000,
    ]);
    $factory->request([
        'uri' => 'http://localhost/boom',
        'method' => 'GET',
        'response_status' => 500,
        'duration' => 50,
    ]);

    $factory->persist();
});

it('lists request entries with type flags', function () {
    inspect(['requests' => true])
        ->expectsOutputToContain('Requests')
        ->expectsOutputToContain('/checkout')
        ->assertExitCode(0);
});

it('filters requests by route pattern', function () {
    inspect(['requests' => true, 'route' => '*checkout*'])
        ->expectsOutputToContain('showing 1 of 5')
        ->assertExitCode(0);
});

it('filters requests by method', function () {
    inspect(['requests' => true, 'method' => 'POST'])
        ->expectsOutputToContain('showing 1 of 5')
        ->assertExitCode(0);
});

it('filters requests by status code', function () {
    inspect(['requests' => true, 'status' => '500'])
        ->expectsOutputToContain('showing 1 of 5')
        ->assertExitCode(0);

    inspect(['requests' => true, 'status' => '200'])
        ->expectsOutputToContain('showing 3 of 5')
        ->assertExitCode(0);
});

it('filters by minimum duration across types', function () {
    inspect(['requests' => true, 'min-duration' => '1000'])
        ->expectsOutputToContain('showing 1 of 5')
        ->assertExitCode(0);
});

it('limits the number of shown entries per type', function () {
    telescopeFactory()->persist(); // no-op flush

    $factory = telescopeFactory();
    foreach (range(1, 10) as $i) {
        $factory->add('request', entryUuid(), null, [
            'uri' => "http://localhost/paged/{$i}",
            'method' => 'GET',
            'response_status' => 200,
            'duration' => 10 * $i,
        ]);
    }
    $factory->persist();

    inspect(['requests' => true, 'limit' => 3])
        ->expectsOutputToContain('showing 3 of 15')
        ->assertExitCode(0);
});

it('filters by the last duration window', function () {
    // Fully self-contained: freeze the clock BEFORE creating fixtures so
    // window arithmetic is deterministic.
    Carbon::setTestNow('2026-08-22 12:00:00');

    $factory = telescopeFactory();
    $factory->request(['uri' => 'http://localhost/fresh']);
    $factory->request(['uri' => 'http://localhost/stale'], Carbon::parse('2026-08-20 12:00:00'));
    $factory->persist();

    // --last sets an open-ended lower bound: the stale entry (2 days before
    // the frozen "now") must be gone, everything recorded later stays.
    inspect(['requests' => true, 'last' => '1h'])
        ->expectsOutputToContain('/fresh')
        ->doesntExpectOutputToContain('/stale')
        ->assertExitCode(0);

    // A bounded window with no entries at all shows the empty state.
    inspect(['requests' => true, 'from' => '2099-01-01', 'to' => '2099-01-02'])
        ->expectsOutputToContain('No Telescope entries found')
        ->assertExitCode(0);
});
