# Telescope Inspect

[![Latest Version on Packagist](https://img.shields.io/packagist/v/mrpunyapal/telescope-inspect.svg?style=flat-square)](https://packagist.org/packages/mrpunyapal/telescope-inspect)
[![Total Downloads](https://img.shields.io/packagist/dt/mrpunyapal/telescope-inspect.svg?style=flat-square)](https://packagist.org/packages/mrpunyapal/telescope-inspect)
[![CI](https://github.com/MrPunyapal/telescope-inspect/actions/workflows/tests.yml/badge.svg?branch=main)](https://github.com/MrPunyapal/telescope-inspect/actions/workflows/tests.yml)
[![License](https://img.shields.io/packagist/l/mrpunyapal/telescope-inspect.svg?style=flat-square)](https://packagist.org/packages/mrpunyapal/telescope-inspect)

Query Laravel Telescope data from the command line. It prints readable summaries for people and JSON for scripts, CI jobs, and other tools.

Telescope records a lot. Answering questions like "which routes were slow this hour" or "what keeps failing on the queue" usually means clicking through the dashboard or writing SQL against `telescope_entries`. This package gives you a command for it:

```bash
php artisan telescope:inspect --requests --last=1h
```

```text
Requests Â· showing 50 of 184 Â· last 1h
--------------------------------------

 Avg 1.12s Â· P95 3.80s Â· Statuses: 200Ã—171   500Ã—9   302Ã—4

 Method  URI                       Reqs   Avg     P95     Avg queries
 GET     /orders                   42     842ms   1.70s   38
 POST    /checkout                 17     1.94s   3.80s   61
```

## Installation

Requires PHP 8.3 or newer, Laravel 11, 12, or 13, and Laravel Telescope ^5.0 with its migrations run.

```bash
composer require --dev mrpunyapal/telescope-inspect
```

The service provider is discovered automatically. Nothing else to configure.

## Usage

Run it with no arguments for an overview of entry counts per type:

```bash
php artisan telescope:inspect
```

Pick entry types with flags:

```bash
php artisan telescope:inspect --requests --last=1h          # slow requests
php artisan telescope:inspect --queries --min-duration=500  # heavy queries
php artisan telescope:inspect --exceptions --last=24h       # recent exceptions
php artisan telescope:inspect --jobs --failed --last=24h    # failed jobs
```

All 18 Telescope entry types are supported: requests, queries, exceptions, jobs, commands, schedule, cache, dumps, events, gates, http (outgoing client requests), logs, mail, models, notifications, redis, views, batches. Each type has a fixed set of normalized fields; see [the field reference](https://mrpunyapal.github.io/telescope-inspect/json-output).

### Filters

| Filter | Example |
| --- | --- |
| Time window | `--last=15m` Â· `--from=2026-08-01` Â· `--to="2026-08-02 14:30"` |
| Limit | `--limit=100` |
| Duration | `--min-duration=250` (milliseconds) |
| Route | `--route="*orders*"` or `--route=OrderController@store` |
| HTTP | `--method=GET,POST` Â· `--status=500,404` |
| Queue | `--failed` Â· `--connection=redis` |
| Free text | `--search=checkout` (matches tags or content) |

Filters combine. For example:

```bash
php artisan telescope:inspect --http --status=500 --method=POST --last=6h
```

### Single entries

```bash
php artisan telescope:inspect --show=<uuid>
```

Prints every normalized field for one entry. Sensitive fields need `--full`.

### Replaying and watching

```bash
php artisan telescope:inspect --batch=<batch-id>    # full lifecycle of one request
php artisan telescope:inspect --exceptions --watch  # tail new exceptions live
```

### Analysis

Selecting requests, queries, exceptions, or jobs adds summaries:

- Requests: average and P95 duration, status codes, slowest routes, queries per route.
- Queries: slowest queries with caller location, most repeated SQL patterns, and likely N+1 detection (identical SQL repeated within one request). The N+1 check is a heuristic and is labeled as such.
- Exceptions: signatures grouped by class, file, and line, with counts.
- Jobs: status and queue distribution, failures with exception messages.

### JSON

Add `--json` for machine-readable output. The output is valid JSON with no formatting or extra text on stdout:

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

The envelope is versioned. Within schema version 1.x, existing keys keep their meaning and new keys may appear.

There is also `--ndjson` for one compact JSON object per line, which is convenient with tools like jq:

```bash
php artisan telescope:inspect --queries --last=1h --ndjson | jq 'select(.duration_ms > 500)'
```

### Monitored tags

Telescope keeps entries that carry a monitored tag even when recording is paused. Manage them from the CLI:

```bash
php artisan telescope:monitor list
php artisan telescope:monitor add --tag="App\Jobs\*"
php artisan telescope:monitor remove --tag="App\Jobs\*"
```

### AI agents

If the command runs under an AI coding agent (Claude Code, Cursor, OpenCode, Codex, Copilot and others, detected with [laravel/agent-detector](https://github.com/MrPunyapal/laravel-agent-detector)), output switches to the JSON contract automatically, so an agent can run the same command a human would and still get parseable data. The envelope carries an `agent` key naming what was detected.

Force human tables with `--human`, or disable the behavior entirely with the `auto_json_for_agents` config key.

### Laravel Boost

The package ships a Boost skill (`resources/boost/skills/telescope-inspect`) that teaches agents when and how to use these commands, how to read the JSON envelope, and how to interpret the N+1 evidence. It installs automatically with `php artisan boost:install` in any Laravel project that has this package installed.

### Exit codes

| Code | Meaning |
| --- | --- |
| 0 | Success |
| 1 | Runtime failure (missing Telescope tables, unknown UUID) |
| 2 | Invalid usage (bad filter values or combinations) |
| 3 | Issues found via `--fail-on` |

Finding issues does not fail the command unless you pass `--fail-on`:

```bash
php artisan telescope:inspect --fail-on=exceptions,failed-jobs --last=15m
php artisan telescope:inspect --fail-on=slow-queries --min-duration=1000 --last=1h
```

## Privacy

Telescope already masks configured hidden parameters before storing anything. This package additionally omits fields that tend to contain sensitive values (request payloads, headers, sessions, query bindings, stack traces, cache values) from all output. Pass `--full` when you actually need them, and treat that output as secret. The package makes no network requests.

## Configuration

Defaults work without publishing anything. If you want to change redaction, truncation, scan limits, or the slow threshold:

```bash
php artisan vendor:publish --tag=telescope-inspect-config
```

See [docs/configuration](https://mrpunyapal.github.io/telescope-inspect/configuration) for the available keys.

## Supported versions

| Package | Versions |
| --- | --- |
| PHP | ^8.3 |
| Laravel | ^11.0 \| ^12.0 \| ^13.0 |
| Laravel Telescope | ^5.0 |

## How it works

```
Telescope storage
      â†“
EntryRepository          SQL filtering, bounded scans
      â†“
ContentNormalizer        raw content arrays to stable normalized fields
      â†“
Analyzers                request, query, exception, job aggregation
      â†“
InspectionResult         typed result object
      â†“
HumanPresenter Â· JsonPresenter
```

The Artisan command is a thin wrapper around `TelescopeInspector::inspect(InspectFilters): InspectionResult`. If you want to build tooling on top (an MCP server, an IDE plugin), use that service instead of querying Telescope yourself.

Scans are bounded (`scan_limit`, default 5000 newest rows) and long values are truncated (`value_limit`, default 1000 characters), so large Telescope tables stay safe to query.

## Testing

```bash
composer test       # Pest suite against Testbench and real Telescope migrations
composer analyse    # PHPStan / Larastan level 6
composer lint       # Pint
composer check      # all of the above plus composer validate
```

Tests insert realistic Telescope rows into Telescope's actual migrations and query them back. Storage is not mocked.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) and the [documentation site](https://mrpunyapal.github.io/telescope-inspect).

## Security

Report vulnerabilities privately as described in [SECURITY.md](SECURITY.md).

## Changelog

Notable changes are listed in [CHANGELOG.md](CHANGELOG.md).

## License

MIT. See [LICENSE](LICENSE).
