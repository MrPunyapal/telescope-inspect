# Telescope Inspect

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

## What it does

- Lists any of the 18 Telescope entry types with filters (time window, duration, route, status, connection, free text search).
- Summarizes requests, queries, exceptions, and jobs: slow routes with P95 durations, repeated SQL patterns, likely N+1 detection, exception signatures, failing jobs.
- Emits a versioned JSON envelope with `--json`, or one JSON object per line with `--ndjson`.
- Exits non-zero on demand via `--fail-on` for CI pipelines.
- Redacts fields that tend to contain sensitive values unless you ask for them.

Everything runs locally against Telescope's own storage tables. The package makes no network requests.

## Requirements

| Package | Version |
| --- | --- |
| PHP | ^8.3 |
| Laravel | 11 / 12 / 13 |
| Laravel Telescope | ^5.0 |

## A look at the JSON

```bash
php artisan telescope:inspect --requests --queries --exceptions --jobs --last=1h --json
```

```json
{
    "schema_version": "1.0",
    "command": "telescope:inspect",
    "generated_at": "2026-08-22T12:00:00.000000Z",
    "filters": { "types": ["request", "query"], "last": "1h" },
    "summary": {
        "total_entries_in_window": 184,
        "entries_by_type": { "request": 42 },
        "analysis": {
            "request": {
                "avg_duration_ms": 1120.5,
                "p95_duration_ms": 3800,
                "routes": [{ "uri": "/orders", "requests": 42 }]
            }
        }
    },
    "violations": [],
    "items": [{ "uuid": "...", "type": "request", "duration_ms": 842 }]
}
```

The full contract is documented in [json-output.md](json-output.md).

## Next steps

- [Installation](installation.md)
- [Usage & filters](usage.md)
- [JSON output contract](json-output.md)
- [Analysis features](analysis.md)
- [Configuration](configuration.md)
- [Testing & contributing](testing-and-contributing.md)
