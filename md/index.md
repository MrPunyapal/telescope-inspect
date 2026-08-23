# Telescope Inspect

[![Latest Version on Packagist](https://img.shields.io/packagist/v/mrpunyapal/telescope-inspect.svg?style=flat-square)](https://packagist.org/packages/mrpunyapal/telescope-inspect)
[![Total Downloads](https://img.shields.io/packagist/dt/mrpunyapal/telescope-inspect.svg?style=flat-square)](https://packagist.org/packages/mrpunyapal/telescope-inspect)
[![License](https://img.shields.io/packagist/l/mrpunyapal/telescope-inspect.svg?style=flat-square)](https://packagist.org/packages/mrpunyapal/telescope-inspect)

Laravel Telescope inspection from the command line — human-friendly output with first-class JSON for scripts, CI, AI agents, and tooling.

Telescope is brilliant at recording what your application did. But answering questions like *"which routes were slow in the last hour?"*, *"what keeps failing on the queue?"*, or *"is this endpoint doing an N+1?"* means opening a browser dashboard and clicking around.

**Telescope Inspect** turns Telescope's recorded data into a queryable, summarizable dataset you can drive from the terminal:

```bash
php artisan telescope:inspect --requests --last=1h
```

```text
Requests · showing 50 of 184 · last 1h
───────────────────────────────────────
Avg 1.12s · P95 3.80s · Statuses: 200×171   500×9   302×4

Method  URI                      Reqs   Avg      P95     Avg queries
GET     /orders                  42     842ms    1.70s   38
POST    /checkout                17     1.94s    3.80s   61
...
```

## Why it exists

- **Humans** get readable summaries instead of thousands of rows: slowest routes, most time-consuming queries, recurring exceptions, failing jobs.
- **Scripts and CI** get stable JSON (`--json`, `--ndjson`) and meaningful exit codes (`--fail-on=...`).
- **AI agents and MCP adapters** get a clean, normalized observability data source — no screen scraping, no database coupling.
- **Everyone** stays local: no data leaves your machine, ever.

## Highlights

- All 18 Telescope entry types inspectable, each with normalized, documented fields
- Database-side time filtering with human durations (`--last=15m`, `--last=7d`) or explicit windows (`--from` / `--to`)
- Content-aware filters: `--route`, `--method`, `--status`, `--min-duration`, `--connection`, `--search`
- Analysis built in: route percentiles, slow/frequent queries, **likely N+1 detection**, exception signatures, job failure tracking
- Bounded by design: scan limits and value truncation keep huge Telescope tables safe to query
- Privacy-first: sensitive fields (payloads, bindings, stack traces) redacted unless explicitly requested

## Requirements

| Package | Version |
| --- | --- |
| PHP | ^8.2 |
| Laravel | 11 / 12 / 13 |
| Laravel Telescope | ^5.0 |

## A taste of the JSON contract

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
    "items": [{ "uuid": "...", "type": "request", "duration_ms": 842 }]
}
```

Feed that straight into `jq`, a CI gate, an IDE extension, or your favorite agent.

## Next steps

- [Installation](installation.md)
- [Usage & filters](usage.md)
- [JSON output contract](json-output.md)
- [Analysis features](analysis.md)
- [Configuration](configuration.md)
- [Testing & contributing](testing-and-contributing.md)
