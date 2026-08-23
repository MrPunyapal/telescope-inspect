<?php

namespace MrPunyapal\TelescopeInspect\Output;

/**
 * Human-friendly duration formatting shared by presenters.
 */
final class Duration
{
    /**
     * Format milliseconds as a compact, readable duration.
     */
    public static function milliseconds(mixed $ms): string
    {
        if ($ms === null || ! is_numeric($ms)) {
            return '-';
        }

        $ms = (float) $ms;

        return match (true) {
            $ms < 1 => sprintf('%.2fms', $ms),
            $ms < 10 => sprintf('%.1fms', $ms),
            $ms < 1000 => sprintf('%.0fms', round($ms)),
            default => sprintf('%.2fs', $ms / 1000),
        };
    }
}
