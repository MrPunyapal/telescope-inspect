<?php

use MrPunyapal\TelescopeInspect\Analysis\Percentiles;
use MrPunyapal\TelescopeInspect\Output\Duration;

it('computes means', function () {
    expect(Percentiles::mean([]))->toBeNull()
        ->and(Percentiles::mean([100]))->toBe(100.0)
        ->and(Percentiles::mean([10, 20, 30]))->toBe(20.0)
        ->and(Percentiles::mean([1, 2]))->toBe(1.5);
});

it('computes percentiles with interpolation', function () {
    expect(Percentiles::percentile([], 95))->toBeNull()
        ->and(Percentiles::percentile([42], 95))->toBe(42.0)
        ->and(Percentiles::percentile([1, 2, 3, 4], 50))->toBe(2.5)
        // 100 values 1..100: p50 = 50.5 (interpolated), p95 = 95.05
        ->and(Percentiles::percentile(range(1, 100), 50))->toBe(50.5)
        ->and(Percentiles::percentile(range(1, 100), 95))->toBe(95.05)
        ->and(Percentiles::percentile(range(1, 100), 0))->toBe(1.0)
        ->and(Percentiles::percentile(range(1, 100), 100))->toBe(100.0);
});

it('rejects out of range percentiles', function () {
    expect(Percentiles::percentile([1], 150))->toBeNull()
        ->and(Percentiles::percentile([1], -1))->toBeNull();
});

it('formats milliseconds for humans', function () {
    expect(Duration::milliseconds(null))->toBe('-')
        ->and(Duration::milliseconds(0.25))->toBe('0.25ms')
        ->and(Duration::milliseconds(5.44))->toBe('5.4ms')
        ->and(Duration::milliseconds(250))->toBe('250ms')
        ->and(Duration::milliseconds(1940.5))->toBe('1.94s');
});
