---
name: telescope-inspect
description: >
  Use when investigating a Laravel application's runtime behavior through
  Telescope data from the command line: slow endpoints, repeated SQL,
  likely N+1 patterns, exceptions, failed jobs, request lifecycles, live
  traffic, or CI checks over recent Telescope entries. Also use after
  changing code to verify performance impact against recorded traffic.
metadata:
  agent: any
---

# Telescope Inspect

Query Laravel Telescope's storage from Artisan. Human tables by default;
JSON when the session runs under an AI coding agent (already automatic).
Everything reads local Telescope tables. No network access exists.

## Command cheat sheet

```bash
# Overview of everything recorded, per type
php artisan telescope:inspect

# Slow requests in a window, with route table (avg, P95, queries/request)
php artisan telescope:inspect --requests --last=1h

# Heavy or repeated queries
php artisan telescope:inspect --queries --min-duration=500 --last=6h

# Exceptions grouped into signatures
php artisan telescope:inspect --exceptions --last=24h

# Failed jobs with exception messages
php artisan telescope:inspect --jobs --failed --last=24h

# Full lifecycle of one request or job, chronologically
php artisan telescope:inspect --batch=<batch-id>

# Every stored field of one entry (add --full for sensitive fields)
php artisan telescope:inspect --show=<uuid>

# Tail new entries as they happen
php artisan telescope:inspect --exceptions --watch
```

Other types: `--commands --schedule --cache --dumps --events --gates --http --logs --mail --models --notifications --redis --views --batches`.

## Output rules for agents

- JSON is returned automatically under agents; pass `--human` to get tables.
- `--ndjson` gives one JSON object per line for streaming.
- The envelope has: `schema_version`, `filters`, `summary`
  (`total_entries_in_window`, `entries_by_type`, `analysis`, `scan`),
  `violations`, `items`. Each item carries `uuid`, `type`, `batch_id`,
  `created_at` plus normalized fields.
- Durations end in `_ms`.
- Sensitive fields (payloads, bindings, traces) are removed unless `--full`.

## Interpreting results

- `summary.analysis.query.likely_n_plus_one` lists identical SQL executed
  many times within one request batch. This is evidence, not a verdict:
  confirm in code whether the repetition is unintended.
- `summary.analysis.request.routes[]` is ranked by P95 duration. Use
  `avg_queries_per_request` to spot routes doing disproportionate database
  work.
- `summary.scan.truncated = true` means the bounded newest-first scan hit
  its ceiling; widen `--last` carefully or raise `scan_limit` in config.
  Each analysis also reports rows_scanned so partial coverage is visible.
- Exceptions are grouped by class + file + line. Use `--show=<uuid>` on a
  sample for the full context.

## CI usage

```bash
php artisan telescope:inspect --fail-on=exceptions,failed-jobs --last=15m
```

Exit codes: 0 success, 1 runtime failure, 2 invalid usage, 3 issues found.
Checks: `exceptions`, `failed-jobs`, `slow-requests`, `slow-queries`.
Pair gates with a short window so old failures do not block every build.

## Good habits

- Always scope investigations with `--last` or `--from/--to`; unbounded
  windows on busy apps waste tokens on noise.
- Use `--batch=<id>` before reading individual entries; the lifecycle
  usually explains the problem faster than isolated rows.
- After fixing something, re-run the same command with a fresh short
  window to verify the fix against real traffic.
