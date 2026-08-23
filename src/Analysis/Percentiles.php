<?php

namespace MrPunyapal\TelescopeInspect\Analysis;

/**
 * Small numeric helpers for duration statistics.
 */
final class Percentiles
{
    /**
     * Arithmetic mean of the given values.
     *
     * @param  list<int|float>  $values
     */
    public static function mean(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        return round(array_sum($values) / count($values), 2);
    }

    /**
     * The p-th percentile using linear interpolation between ranks.
     *
     * @param  list<int|float>  $values
     */
    public static function percentile(array $values, int $p): ?float
    {
        if ($values === [] || $p < 0 || $p > 100) {
            return null;
        }

        sort($values);

        if (count($values) === 1) {
            return round((float) $values[0], 2);
        }

        $index = ($p / 100) * (count($values) - 1);
        $lower = (int) floor($index);
        $upper = (int) ceil($index);

        if ($lower === $upper) {
            return round((float) $values[$index], 2);
        }

        $weight = $index - $lower;

        return round((float) ($values[$lower] * (1 - $weight) + $values[$upper] * $weight), 2);
    }
}
