---
order: 4
---

# JSON output

The `--json` output is valid JSON with no ANSI codes or decorative text on stdout, so you can pipe it straight to files, `jq`, CI steps, IDE extensions, MCP adapters, or AI agents.

```bash
php artisan telescope:inspect --requests --queries --exceptions --jobs --last=1h --json > telescope.json
```

## Envelope

```json
{
    "schema_version": "1.0",
    "command": "telescope:inspect",
    "generated_at": "2026-08-22T12:00:00.000000Z",
    "agent": null,
    "filters": {
        "types": ["request", "query"],
        "last": "1h",
        "from": null,
        "to": null,
        "resolved_window_utc": { "from": "...", "to": null },
        "limit": 50,
        "min_duration_ms": null,
        "route": null,
        "methods": [],
        "statuses": [],
        "failed_jobs_only": false,
        "connection": null,
        "search": null,
        "batch_id": null,
        "full": false,
        "fail_on": []
    },
    "summary": { "total_entries_in_window": 184 },
    "violations": [],
    "items": []
}
```

(Abridged example; `agent`, `summary.scan` and the other keys below are always emitted.)

- **schema_version**: bump-major contract. Within `1.x`, existing fields keep their meaning; new fields may appear. A formal JSON Schema lives at [`schema/telescope-inspect-v1.schema.json`](https://github.com/MrPunyapal/telescope-inspect/blob/main/schema/telescope-inspect-v1.schema.json).
- **generated_at**: UTC ISO 8601.
- **agent**: name of the detected AI agent when JSON was auto-selected for it, otherwise `null`.
- **filters**: exactly what you asked for plus the resolved UTC window. `batch_id` is `null` unless `--batch` is used. `--show` is intentionally not echoed: it selects an entry directly.
- **summary.total_entries_in_window**: exact count of Telescope entries in the window across **all** types (not just the selected flags). In `--batch` mode it is the number of entries in that batch.
- **summary.entries_by_type**: per-type counts (only types with entries).
- **summary.items_returned**: how many items were included per type (`--limit` caps the combined listing, newest first).
- **summary.analysis**: analyzer results for requests, queries, exceptions and jobs when those types are selected ([details](analysis.md)). Each summary carries `*_analyzed` and `rows_scanned` counters so partial coverage is visible.
- **summary.content_filters_present**: whether any content filter (--min-duration, --route, --method, --status, --failed, --connection, --search) was supplied. Analysis always covers the whole window regardless of these filters.
- **summary.scan**: `{ "limit", "truncated" }`, the bounded newest-first scan ceiling and whether it was hit while more entries were requested. `truncated: false` guarantees the whole window for the selected types was examined; analyzer walks expose their own coverage through `rows_scanned`.
- **violations**: `--fail-on` checks that matched (empty array otherwise); mirrors exit code 3.

For `--show=<uuid>` the envelope contains a single `"entry"` object instead of `"items"` and has no `violations` key. A missing UUID exits `1` in every mode: humans see a styled error, machine modes get the message on stderr with an empty stdout.

## Items

Each item is one normalized entry. Common keys:

| Key | Always present |
| --- | --- |
| `uuid` | yes |
| `type` | yes, the Telescope entry type string |
| `batch_id` | nullable |
| `created_at` | nullable, UTC ISO 8601 |

Type-specific fields are merged at the same level. Anything ending in `_ms` is milliseconds.

### Example: query

```json
{
    "sql": "select * from `orders` where `user_id` = ?",
    "connection": "mysql",
    "driver": "mysql",
    "duration_ms": 12.34,
    "slow": false,
    "file": "/app/app/Models/Order.php",
    "line": 55,
    "query_hash": "9f8a...",
    "uuid": "...",
    "type": "query",
    "batch_id": "...",
    "created_at": "2026-08-22T11:59:59.000000Z"
}
```

### Field reference per type

| Type | Fields | + sensitive (only with `--full`) |
| --- | --- | --- |
| request | method, uri (path only), controller_action, middleware[], response_status, duration_ms, memory_mb, ip_address | query_string, headers, payload, session, response, response_headers |
| client_request | method, uri (path only), response_status, duration_ms | query_string, headers, payload, response, response_headers |
| query | sql, connection, driver, duration_ms, slow, file, line, query_hash | bindings |
| exception | class, message, file, line, occurrences | context, trace, line_preview |
| job | name, queue, connection, status (pending/processed/failed), tries, timeout, exception_message | data |
| command | command, exit_code | arguments, options |
| schedule | command, description, expression, timezone, user | output |
| cache | operation (hit/missed/set/forget), key, expiration | value |
| dump | entry_point_type, entry_point_uuid | dump |
| event | name, listeners[], broadcast | payload |
| gate | ability, result, message, file, line | arguments |
| log | level, message | context |
| mail | mailable, subject, queued, from[], to[], cc[], bcc[], reply_to[] | html, raw |
| model | action, model, count | changes |
| notification | notification, channel, queued, notifiable | response |
| redis | command (verb and key), connection, duration_ms | arguments |
| view | name, path, shared_keys[], composers[] | none |
| batch | name, total_jobs, pending_jobs, failed_jobs, processed_jobs, progress, queue, connection, allows_failures, cancelled_at, finished_at | options |

Analysis summaries cap their output: top 10 routes / query patterns / signatures / recurring failures, top 5 N+1 candidates and latest exceptions. `summary.analysis.*.rows_scanned` tells you exactly how much of the window fed them.

Under default redaction the sensitive fields listed above are removed from items entirely (the keys are absent), not set to `null`. When `--full` is enabled the same fields appear; a field Telescope never recorded is then present as `null`. Consumers can therefore distinguish "intentionally omitted" from "not recorded".

Nullable non-sensitive fields may be omitted values (`null`) rather than missing keys; consumers should tolerate both.

## Sensitive values

Fields such as `payload`, `headers`, `session`, `query_string`, `bindings`, `trace`, `context`, `data`, `value`, `dump`, and `output` are **redacted by default**: they are removed from JSON entirely. Pass `--full` (or set `redact_sensitive: false` in config) to include them, but only do this on machines where that data is safe to expose.

Note two deliberate residual exposures: request URIs keep their path (query strings are gated) but free-text fields like SQL statements, log/exception messages, mail subjects, addresses, and cache keys are always visible - they are the point of those entries.

All string values are truncated at `value_limit` characters (default 1000) so a single huge payload can never blow up a consumer.

## NDJSON

`--ndjson` emits one compact JSON item per line, ideal for streaming into `jq`:

```bash
php artisan telescope:inspect --queries --last=1h --ndjson | jq 'select(.duration_ms > 500)'
```

Requires at least one type flag, `--batch=<id>`, or `--show=<uuid>`. Combine with `--full` as needed. Violation notes go to stderr so piped lines stay parseable.

## Exit codes

| Code | Meaning |
| --- | --- |
| 0 | success (including valid empty results) |
| 1 | runtime failure: storage missing, UUID or batch id not found |
| 2 | invalid usage: bad values or impossible combinations |
| 3 | issues found via `--fail-on` |

In JSON and NDJSON modes stdout carries data only; diagnostics are plain text on stderr, so `2>&1` is safe but never required.
