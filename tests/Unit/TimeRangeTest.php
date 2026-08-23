<?php

use MrPunyapal\TelescopeInspect\Filters\InvalidFilter;
use MrPunyapal\TelescopeInspect\Filters\TimeRange;

it('parses human friendly durations', function (string $input, int $expectedSeconds) {
    $range = TimeRange::last($input);

    expect($range->from)->not->toBeNull()
        ->and($range->to)->toBeNull()
        // Allow one second of drift between parse and assertion.
        ->and(now()->getTimestamp() - $range->from->getTimestamp())->toBeBetween($expectedSeconds - 1, $expectedSeconds + 1);
})->with([
    ['30s', 30],
    ['15m', 900],
    ['1h', 3600],
    ['24h', 86400],
    ['7d', 604800],
    ['2w', 1209600],
]);

it('rejects invalid durations', function (string $input) {
    TimeRange::last($input);
})->throws(InvalidFilter::class)->with([
    ['5x'],
    ['h'],
    ['-1h'],
    ['0h'],
    ['1.5h'],
    [''],
]);

it('builds explicit ranges from from and to', function () {
    $range = TimeRange::between('2026-08-01 00:00:00', '2026-08-02 12:00:00');

    expect($range->from->format('Y-m-d H:i'))->toBe('2026-08-01 00:00')
        ->and($range->to->format('Y-m-d H:i'))->toBe('2026-08-02 12:00');
});

it('allows open ended explicit ranges', function () {
    $onlyFrom = TimeRange::between('2026-08-01', null);
    $onlyTo = TimeRange::between(null, '2026-08-01');

    expect($onlyFrom->from)->not->toBeNull()->and($onlyFrom->to)->toBeNull()
        ->and($onlyTo->from)->toBeNull()->and($onlyTo->to)->not->toBeNull();
});

it('rejects inverted ranges', function () {
    TimeRange::between('2026-08-05', '2026-08-01');
})->throws(InvalidFilter::class, '--from [2026-08-05] must not be after --to [2026-08-01].');

it('rejects garbage boundaries', function () {
    TimeRange::between('not-a-date', null);
})->throws(InvalidFilter::class, 'Invalid date/time');

it('applies the application timezone to explicit boundaries', function () {
    config(['app.timezone' => 'America/New_York']);

    $range = TimeRange::between('2026-08-01 06:00:00', null);

    expect($range->from->format('H:i'))->toBe('06:00')
        ->and($range->from->tzName)->toBe('America/New_York');
});
