<?php

namespace MrPunyapal\TelescopeInspect\Filters;

use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * A resolved time window used to filter Telescope entries.
 *
 * All boundaries are timezone-aware; the application timezone is applied when
 * parsing explicit --from / --to values so results match what the user sees
 * in the rest of the application.
 */
final class TimeRange
{
    /**
     * Human-friendly duration abbreviations mapped to Carbon units.
     */
    private const UNITS = [
        's' => 'seconds',
        'sec' => 'seconds',
        'secs' => 'seconds',
        'second' => 'seconds',
        'seconds' => 'seconds',
        'm' => 'minutes',
        'min' => 'minutes',
        'mins' => 'minutes',
        'minute' => 'minutes',
        'minutes' => 'minutes',
        'h' => 'hours',
        'hr' => 'hours',
        'hrs' => 'hours',
        'hour' => 'hours',
        'hours' => 'hours',
        'd' => 'days',
        'day' => 'days',
        'days' => 'days',
        'w' => 'weeks',
        'week' => 'weeks',
        'weeks' => 'weeks',
    ];

    private function __construct(
        public readonly ?Carbon $from,
        public readonly ?Carbon $to,
    ) {}

    /**
     * A range covering the given human duration up to now, e.g. "15m", "1h", "7d".
     *
     * @throws InvalidFilter
     */
    public static function last(string $duration): self
    {
        try {
            $interval = self::parseDuration($duration);
        } catch (InvalidArgumentException $e) {
            throw new InvalidFilter($e->getMessage(), previous: $e);
        }

        return new self(now()->sub($interval), null);
    }

    /**
     * An explicit range. Either bound may be omitted to leave it open-ended,
     * but at least one bound must be provided.
     *
     * @throws InvalidFilter
     */
    public static function between(?string $from, ?string $to): self
    {
        if ($from === null && $to === null) {
            throw new InvalidFilter('Provide at least one of --from or --to.');
        }

        $resolvedFrom = $from !== null ? self::parseBoundary('--from', $from) : null;
        $resolvedTo = $to !== null ? self::parseBoundary('--to', $to) : null;

        if ($resolvedFrom !== null && $resolvedTo !== null && $resolvedFrom->gt($resolvedTo)) {
            throw new InvalidFilter("--from [{$from}] must not be after --to [{$to}].");
        }

        return new self($resolvedFrom, $resolvedTo);
    }

    /**
     * Parse a human-friendly duration such as "30s", "15m", "1h" or "7d".
     *
     * @throws InvalidArgumentException
     */
    public static function parseDuration(string $duration): \DateInterval
    {
        $normalized = strtolower(trim($duration));

        if (preg_match('/^(\d+)\s*([a-z]+)$/', $normalized, $matches) === 1 && $matches[1] > 0) {
            $unit = self::UNITS[$matches[2]] ?? null;

            if ($unit !== null) {
                return \DateInterval::createFromDateString("{$matches[1]} {$unit}");
            }
        }

        throw new InvalidArgumentException(
            "Invalid duration [{$duration}]. Use formats like 30s, 15m, 1h, 24h or 7d."
        );
    }

    /**
     * @throws InvalidFilter
     */
    private static function parseBoundary(string $option, string $value): Carbon
    {
        try {
            return Carbon::parse(trim($value), config('app.timezone'));
        } catch (\Throwable $e) {
            throw new InvalidFilter(
                "Invalid date/time for {$option} [{$value}]. Use formats like \"2026-08-01\", \"2026-08-01 14:30\" or \"2026-08-01T14:30:00\".",
                previous: $e
            );
        }
    }

    /**
     * An open-ended range matching every entry.
     */
    public static function unbounded(): self
    {
        return new self(null, null);
    }
}
