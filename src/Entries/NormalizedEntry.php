<?php

namespace MrPunyapal\TelescopeInspect\Entries;

use Illuminate\Support\Carbon;

/**
 * A normalized, stable representation of a single Telescope entry.
 *
 * The command layer and presenters work exclusively against these objects;
 * raw Telescope storage details never leak past the query layer.
 */
final class NormalizedEntry
{
    /**
     * @param  array<string, mixed>  $fields  type-specific normalized fields
     * @param  list<string>  $tags
     */
    public function __construct(
        public readonly string $uuid,
        public readonly EntryType $type,
        public readonly ?string $batchId,
        public readonly ?Carbon $createdAt,
        public readonly array $fields = [],
        public readonly array $tags = [],
        public readonly ?int $sequence = null,
    ) {}

    /**
     * Read a normalized field by key.
     */
    public function field(string $key, mixed $default = null): mixed
    {
        return $this->fields[$key] ?? $default;
    }

    /**
     * The canonical JSON representation of this entry.
     *
     * Common keys are merged with type-specific fields; type-specific fields
     * may not override common keys.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_merge($this->fields, [
            'uuid' => $this->uuid,
            'type' => $this->type->value,
            'batch_id' => $this->batchId,
            'created_at' => $this->createdAt?->copy()->utc()->toISOString(),
        ]);
    }
}
