# JSON output

`--json` is a first-class product surface, not an afterthought. The output is valid JSON with no ANSI codes or decorative text on stdout — pipe it to files, `jq`, CI steps, IDE extensions, MCP adapters, or AI agents.

```bash
php artisan telescope:inspect --requests --queries --exceptions --jobs --last=1h --json > telescope.json
```

## Envelope

```json
{
    "schema_version": "1.0",
    "command": "telescope:inspect",
    "generated_at": "2026-08-22T12:00:00.000000Z",
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
        "full": false,
        "fail_on": []
    },
    "summary": { "total_entries_in_window": 184 },
    "violations": [],
    "items": []
}
```

- **schema_version** — bump-major contract. Within `1.x`, existing fields keep their meaning; new fields may appear.
- **generated_at** — UTC ISO 8601.
- **filters** - exactly what you asked for plus the resolved UTC window. `batch_id` appears when `--batch` is used. `--show` is intentionally not echoed: it selects an entry directly.
- **summary.total_entries_in_window** — total Telescope entries in the window across all types.
- **summary.entries_by_type** — per-type counts (only types with entries).
- **summary.items_returned** — how many items were included per type (`--limit` caps the combined listing, newest first).
- **summary.analysis** — analyzer results for requests, queries, exceptions and jobs when those types are selected ([details](analysis.md)).
- **summary.analysis_scoped_to_filters** — true when content filters narrowed which rows fed the listing; analysis itself always covers the whole selected window.
- **summary.scan** — `{ "limit", "truncated" }`: the bounded newest-first scan ceiling and whether it was hit. When `truncated` is true, results may be incomplete.
- **violations** — `--fail-on` checks that matched (empty array otherwise); mirrors exit code 3.

For `--show=<uuid>` the envelope contains a single `"entry"` object instead of `"items"`.

## Items

Each item is one normalized entry. Common keys come first-class:

| Key | Always present |
| --- | --- |
| `uuid` | yes |
| `type` | yes — the Telescope entry type string |
| `batch_id` | nullable |
| `created_at` | nullable — UTC ISO 8601 |

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

| Type | Fields |
| --- | --- |
| request | method, uri, controller_action, middleware[], response_status, duration_ms, memory_mb, ip_address |
| client_request | method, uri, response_status, duration_ms |
| query | sql, connection, driver, duration_ms, slow, file, line, query_hash |
| exception | class, message, file, line |
| job | name, queue, connection, status (pending/processed/failed), tries, timeout, exception_message |
| command | command, exit_code |
| schedule | command, description, expression, timezone, user, output |
| cache | operation (hit/missed/set/forget), key, expiration |
| dump | dump |
| event | name, listeners[], broadcast |
| gate | ability, result, message, file, line |
| log | level, message |
| mail | mailable, subject, queued, from[], to[], cc[], bcc[], reply_to[] |
| model | action, model, count |
| notification | notification, channel, queued, notifiable |
| redis | command, connection, duration_ms |
| view | name, path, shared_keys[], composers[] |
| batch | name, total_jobs, pending_jobs, failed_jobs, processed_jobs, progress, queue, connection, allows_failures, cancelled_at, finished_at |

Under default redaction the sensitive fields listed below are removed from items entirely (the keys are absent), not set to `null`.

Nullable fields may be omitted values (`null`) rather than missing keys — consumers should tolerate both.

## Sensitive values

Fields such as `payload`, `headers`, `session`, `bindings`, `trace`, `context`, `data`, and `value` are **redacted by default**: they are removed from JSON entirely. Pass `--full` (or set `redact_sensitive: false` in config) to include them — only do this on machines where that data is safe to expose.

All string values are truncated at `value_limit` characters (default 1000) so a single huge payload can never blow up a consumer.

## NDJSON

`--ndjson` emits one compact JSON item per line — ideal for streaming into `jq`:

```bash
php artisan telescope:inspect --queries --last=1h --ndjson | jq 'select(.duration_ms > 500)'
```

Requires at least one type flag (or `--show`). Combine with `--full` as needed.
