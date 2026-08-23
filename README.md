# Telescope Inspect

[![Latest Version on Packagist](https://img.shields.io/packagist/v/mrpunyapal/telescope-inspect.svg?style=flat-square)](https://packagist.org/packages/mrpunyapal/telescope-inspect)
[![Total Downloads](https://img.shields.io/packagist/dt/mrpunyapal/telescope-inspect.svg?style=flat-square)](https://packagist.org/packages/mrpunyapal/telescope-inspect)
[![CI](https://github.com/MrPunyapal/telescope-inspect/actions/workflows/tests.yml/badge.svg?branch=main)](https://github.com/MrPunyapal/telescope-inspect/actions/workflows/tests.yml)
[![License](https://img.shields.io/packagist/l/mrpunyapal/telescope-inspect.svg?style=flat-square)](https://packagist.org/packages/mrpunyapal/telescope-inspect)

**Laravel Telescope inspection from the command line** — human-friendly output with first-class JSON for scripts, CI, AI agents, and tooling.

Telescope records everything your app does. Answering *"which routes are slow?"*, *"what keeps failing on the queue?"* or *"is this endpoint doing an N+1?"* still means clicking through a dashboard. Telescope Inspect turns Telescope's storage into a queryable dataset you can drive from a terminal:

```bash
php artisan telescope:inspect --requests --last=1h
```

```text
Requests · showing 50 of 184 · last 1h
──────────────────────────────────────
Avg 1.12s · P95 3.80s · Statuses: 200×171   500×9   302×4

Method  URI                       Reqs   Avg     P95     Avg queries
GET     /orders                   42     842ms   1.70s   38
POST    /checkout                 17     1.94s   3.80s   61
```

## Why

- **Humans** get summaries instead of thousands of rows: slowest routes, most expensive queries, exception signatures, failing jobs.
- **Scripts & CI** get stable JSON (`--json`, `--ndjson`) and meaningful exit codes (`--fail-on`).
- **AI agents / MCP adapters** get a normalized, documented observability feed — no screen scraping, no DB coupling.
- **Everyone** stays local. Nothing ever leaves your machine.

## Installation

Requires PHP 8.2+, Laravel 11/12/13 and [Laravel Telescope](https://laravel.com/docs/telescope) ^5.0.

```bash
composer require --dev mrpunyapal/telescope-inspect
```

The service provider is discovered automatically.

## Usage

```bash
# Overview of everything recorded
php artisan telescope:inspect

# Slow requests in the last hour
php artisan telescope:inspect --requests --last=1h

# Heavy queries
php artisan telescope:inspect --queries --min-duration=500

# Exceptions from the last day, grouped by signature
php artisan telescope:inspect --exceptions --last=24h

# Failed jobs
php artisan telescope:inspect --jobs --failed --last=24h

# Combine types and filters freely
php artisan telescope:inspect --requests --queries --exceptions --last=1h

# Machine-readable output
php artisan telescope:inspect --requests --queries --json
```

### Entry types

`--requests` `--queries` `--exceptions` `--jobs` `--commands` `--schedule` `--cache` `--dumps` `--events` `--gates` `--http` `--logs` `--mail` `--models` `--notifications` `--redis` `--views` `--batches`

Every type has a documented, stable set of normalized fields ([field reference](https://mrpunyapal.github.io/telescope-inspect/json-output)).

### Filters

| Filter | Example |
| --- | --- |
| Time window | `--last=15m` · `--from=2026-08-01` · `--to="2026-08-02 14:30"` |
| Limit | `--limit=100` (across all selected types, newest first) |
| Duration | `--min-duration=250` (milliseconds) |
| Route | `--route="*orders*"` or `--route=OrderController@store` (fnmatch against the stored URI such as `/orders`, or substring) |
| HTTP | `--method=GET,POST` · `--status=500,404` |
| Queue | `--failed` · `--connection=redis` |
| Free-text | `--search=checkout` (matches tags or content) |

Filters compose naturally:

```bash
php artisan telescope:inspect --http --status=500 --method=POST --last=6h
```

### Deep-dive a single entry

```bash
php artisan telescope:inspect --show=9d1f8a0e-1c2b-3d4e-5f60-7a8b9c0d1e2f
```

Prints every normalized field for that UUID — sensitive fields require `--full`. Add `--json` for structured consumption.

### Analysis

Selecting an analyzable type adds summaries to both human output and JSON:

- **Requests** — avg/P95 durations, status distribution, slowest routes with average queries per request, the single slowest request.
- **Queries** — slowest queries with caller location, most time-consuming SQL patterns, **likely N+1 detection** (identical SQL repeated within one request batch — reported as *likely*, never certain).
- **Exceptions** — recurring signatures with occurrence counts and last-seen times.
- **Jobs** — status/queue distribution, failures with exception messages, recurring failure ranking.

### JSON output

```bash
php artisan telescope:inspect --requests --queries --exceptions --jobs --last=1h --json
```

```json
{
    "schema_version": "1.0",
    "command": "telescope:inspect",
    "generated_at": "2026-08-22T12:00:00.000000Z",
    "filters": { "types": ["request"], "last": "1h", "limit": 50 },
    "summary": {
        "total_entries_in_window": 184,
        "entries_by_type": { "request": 184 },
        "analysis": {
            "request": {
                "avg_duration_ms": 1120.5,
                "p95_duration_ms": 3800,
                "routes": [{ "uri": "/orders", "requests": 42, "avg_queries_per_request": 38 }]
            }
        }
    },
    "violations": [],
    "items": [{ "uuid": "...", "type": "request", "duration_ms": 842 }]
}
```

Valid JSON only — no ANSI codes or decoration on stdout. Pipe it anywhere:

```bash
php artisan telescope:inspect --queries --last=1h --ndjson | jq 'select(.duration_ms > 500)'
php artisan telescope:inspect --requests --json > telescope.json
```

The envelope is a versioned contract: within `schema_version: 1.x`, existing keys keep their meaning; new keys may appear.

### CI gates

```bash
php artisan telescope:inspect --fail-on=exceptions,failed-jobs --last=15m
php artisan telescope:inspect --fail-on=slow-queries --min-duration=1000 --last=1h
```

Exit codes: `0` success · `1` runtime failure · `2` invalid usage · `3` issues found via `--fail-on`.

Finding issues does **not** fail the command unless you ask for it.

## Privacy

Telescope already masks configured hidden parameters. On top of that, this package omits sensitive fields (payloads, headers, sessions, query bindings, stack traces, cache values...) from all output by default. Pass `--full` to include them when you genuinely need them — treat such output as secret material. All processing is local; no network calls exist in this package.

## Configuration

Defaults are excellent; publishing config is optional:

```bash
php artisan vendor:publish --tag=telescope-inspect-config
```

Available knobs: redaction toggle, value truncation limit, scan limit, analysis bound, slow threshold. See [docs/configuration](https://mrpunyapal.github.io/telescope-inspect/configuration).

## Supported versions

| Package | Versions |
| --- | --- |
| PHP | ^8.2 |
| Laravel | ^11.0 \| ^12.0 \| ^13.0 |
| Laravel Telescope | ^5.0 |

## Architecture

```
Telescope storage
      ↓
EntryRepository          database-side filtering, bounded scans
      ↓
ContentNormalizer        raw content → stable normalized fields
      ↓
Analyzers                route/query/exception/job aggregation
      ↓
InspectionResult         typed result object (the integration point)
      ↓
HumanPresenter · JsonPresenter
```

The Artisan command is a thin wrapper around `TelescopeInspector::inspect(InspectFilters): InspectionResult`. Future MCP adapters or IDE integrations should consume that service directly instead of duplicating query logic.

## Testing

```bash
composer test       # Pest suite against Testbench + real Telescope migrations
composer analyse    # PHPStan / Larastan level 6
composer lint       # Pint
composer check      # all of the above
```

Tests insert realistic Telescope rows into Telescope's actual migrations and assert against real database queries — no storage mocking.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) and the [documentation site](https://mrpunyapal.github.io/telescope-inspect).

## Security

If you discover a security vulnerability, please follow [SECURITY.md](SECURITY.md). Please do not use public issue trackers for security disclosures.

## Changelog

All notable changes live in [CHANGELOG.md](CHANGELOG.md).

## License

MIT — see [LICENSE](LICENSE).
