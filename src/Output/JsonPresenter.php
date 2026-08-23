<?php

namespace MrPunyapal\TelescopeInspect\Output;

use Illuminate\Support\Arr;
use MrPunyapal\TelescopeInspect\InspectionResult;
use MrPunyapal\TelescopeInspect\Normalizers\ContentNormalizer;

/**
 * Renders an InspectionResult as the package's canonical JSON contract.
 *
 * Output is pure JSON: no ANSI codes, no progress noise, no decorations.
 * The envelope is stable; unknown keys may be added over time but existing
 * keys keep their meaning. Items are grouped by selected type in canonical
 * order and newest-first within each type.
 *
 * @internal
 */
final class JsonPresenter
{
    public const SCHEMA_VERSION = '1.0';

    public function __construct(
        private readonly bool $ndjson = false,
        private readonly bool $redactSensitive = true,
        /** @var list<string> */
        private readonly array $violations = [],
    ) {}

    /**
     * Render the result as a JSON string (or NDJSON lines).
     */
    public function render(InspectionResult $result): string
    {
        return $result->singleEntry !== null
            ? $this->renderSingle($result)
            : $this->renderListing($result);
    }

    private function renderListing(InspectionResult $result): string
    {
        if ($this->ndjson) {
            return collect($result->items())
                ->map(fn ($entry): string => $this->encode($this->redact($entry->toArray())))
                ->implode("\n");
        }

        return $this->encode([
            'schema_version' => self::SCHEMA_VERSION,
            'command' => 'telescope:inspect',
            'generated_at' => $result->generatedAt->copy()->utc()->toISOString(),
            'filters' => $result->filters->toArray(),
            'summary' => $this->summary($result),
            'violations' => $this->violations,
            'items' => collect($result->items())
                ->map(fn ($entry) => $this->redact($entry->toArray()))
                ->values()
                ->all(),
        ]);
    }

    private function renderSingle(InspectionResult $result): string
    {
        if ($this->ndjson) {
            return $result->singleEntry === null ? '' : $this->encode($this->redact($result->singleEntry));
        }

        return $this->encode([
            'schema_version' => self::SCHEMA_VERSION,
            'command' => 'telescope:inspect',
            'generated_at' => $result->generatedAt->copy()->utc()->toISOString(),
            'filters' => $result->filters->toArray(),
            'summary' => $this->summary($result),
            'entry' => $result->singleEntry === null ? null : $this->redact($result->singleEntry),
        ]);
    }

    /**
     * The summary object shared by both envelopes.
     *
     * @return array<string, mixed>
     */
    private function summary(InspectionResult $result): array
    {
        return [
            'total_entries_in_window' => $result->totalInWindow,
            'entries_by_type' => $result->countsByType,
            'items_returned' => collect($result->itemsByType)
                ->map(fn ($entries): int => count($entries))
                ->all(),
            'analysis' => $result->summariesByType,
            'analysis_scoped_to_filters' => $result->filters->hasContentFilters(),
            'scan' => [
                'limit' => $result->scanLimit,
                'truncated' => $result->scanTruncated,
            ],
        ];
    }

    /**
     * Drop sensitive fields from an entry array when redaction is enabled.
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function redact(array $entry): array
    {
        if (! $this->redactSensitive) {
            return $entry;
        }

        return Arr::except($entry, ContentNormalizer::SENSITIVE_FIELDS);
    }

    /**
     * Encode with consistent, portable flags.
     *
     * NDJSON lines must stay on one line, so they are always compact;
     * envelopes are pretty-printed for human diffing and review.
     */
    private function encode(mixed $data): string
    {
        $flags = $this->ndjson ? 0 : JSON_PRETTY_PRINT;

        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR | $flags);
    }
}
