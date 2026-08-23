<?php

namespace MrPunyapal\TelescopeInspect\Analysis;

use Illuminate\Support\Collection;
use MrPunyapal\TelescopeInspect\InspectionResult;

/**
 * Evaluates --fail-on checks against an inspection result.
 *
 * This is the single home for the fail-on vocabulary so the CLI exit code,
 * the JSON envelope and any future integration agree on semantics.
 *
 * @internal
 */
final class IssueChecks
{
    /**
     * The canonical checks and the type flag that drills into each.
     *
     * @return array<string, string>
     */
    public static function known(): array
    {
        return [
            'exceptions' => '--exceptions',
            'failed-jobs' => '--jobs',
            'slow-requests' => '--requests',
            'slow-queries' => '--queries',
        ];
    }

    /**
     * Checks that matched, in canonical order.
     *
     * @param  float  $thresholdMs  slow threshold used when --min-duration is absent
     * @return list<string>
     */
    public static function violations(InspectionResult $result, float $thresholdMs): array
    {
        if ($result->filters->failOn === []) {
            return [];
        }

        $analysis = $result->summariesByType;

        /** @var Collection<string, \Closure> $checks */
        $checks = collect([
            'exceptions' => fn (): bool => (($analysis['exception']['exceptions_analyzed'] ?? 0) > 0),
            'failed-jobs' => fn (): bool => (($analysis['job']['failed']['total'] ?? 0) > 0),
            'slow-requests' => fn (): bool => self::hasSlowRequest($analysis, $thresholdMs),
            'slow-queries' => fn (): bool => self::hasSlowQuery($analysis, $thresholdMs),
        ]);

        return collect(self::known())
            ->keys()
            ->filter(fn (string $check): bool => in_array($check, $result->filters->failOn, true))
            ->filter(fn (string $check): bool => $checks->get($check) !== null && ($checks->get($check))())
            ->values()
            ->all();
    }

    /**
     * A request counts as slow when its route-level P95 duration reaches the
     * threshold; outliers are exactly what CI should catch.
     *
     * @param  array<string, mixed>  $analysis
     */
    private static function hasSlowRequest(array $analysis, float $threshold): bool
    {
        foreach ((array) ($analysis['request']['routes'] ?? []) as $route) {
            $p95 = (float) ($route['p95_duration_ms'] ?? $route['avg_duration_ms'] ?? 0);

            if ($p95 >= $threshold) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $analysis
     */
    private static function hasSlowQuery(array $analysis, float $threshold): bool
    {
        foreach ((array) ($analysis['query']['slowest'] ?? []) as $query) {
            if ((float) ($query['duration_ms'] ?? 0) >= $threshold) {
                return true;
            }
        }

        return false;
    }
}
