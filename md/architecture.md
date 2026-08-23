---
order: 6
---

# Architecture

Telescope Inspect is a read-only view over Telescope's own storage. One Artisan command, one pipeline, no background processes.

## Pipeline

```text
telescope:inspect flags
      |
InspectFilters            CLI flags to a validated value object (exit 2 on garbage)
      |
EntryRepository           bounded SQL against telescope_entries
      |                   newest-first, capped by scan_limit, parameterized
      |
ContentNormalizer         raw content JSON to stable per-type fields
      |
Analyzers                 request / query / exception / job aggregation
      |
InspectionResult          typed result object
      |
HumanPresenter            terminal tables        JsonPresenter    --json / --ndjson envelope
```

The Artisan command (`src/Commands/InspectCommand.php`) is a thin wrapper. Everything above it is plain PHP you can call directly:

```php
use MrPunyapal\TelescopeInspect\TelescopeInspector;
use MrPunyapal\TelescopeInspect\Filters\InspectFilters;

$result = app(TelescopeInspector::class)->inspect(
    InspectFilters::fromArray(['types' => ['request'], 'last' => '1h'])
);
```

This is the intended integration point for tooling built on top (MCP servers, IDE plugins, dashboards).

## Storage access rules

- **Read-only**: the package never inserts, updates, or deletes Telescope rows.
- **Bounded scans**: every listing and analysis reads at most `scan_limit` rows (default 5000, clamped to 100..50000), newest first. A huge production table cannot produce an unbounded query.
- **Parameterized SQL only**: all queries go through Laravel's query builder; no string interpolation.
- **Type partitioning**: one bounded fetch is split in memory per type rather than issuing a query per selected type.

## Normalization

Each of the 18 entry types has a fixed field map defined in `ContentNormalizer`. Raw Telescope `content` payloads vary by Telescope version and watcher configuration; normalization guarantees consumers always see the same keys for a type, with absent values as `null`. Sensitive fields are dropped before presentation unless `--full` or `redact_sensitive: false`.

## Analysis scope

Analyzers run over the most recent matching entries within the resolved time window, independent of the listing `--limit`. The N+1 detector is a heuristic: identical normalized SQL executing at least 10 times inside one request batch. It is reported as "likely", never as fact.

## The JSON contract

`JsonPresenter` emits a versioned envelope (`schema_version`). Within `1.x`, existing keys keep their meaning and new keys may be added; breaking changes bump the major version. See [JSON output](json-output.md).

## Testing approach

The Pest suite boots Orchestra Testbench with real Telescope migrations on SQLite and inserts realistic rows via `tests/Fixtures/EntryFactory.php`, then asserts on actual command output. Storage is never mocked, so SQL, normalization, and analysis are exercised end to end.

## What the package deliberately does not do

- No writes, pruning, or pausing of Telescope; use the shipped `telescope:clear`, `telescope:prune`, `telescope:pause`, `telescope:resume`.
- No network calls, telemetry, or license checks.
- No dashboard UI; the JSON envelope exists so tools can build their own.
