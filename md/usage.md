---
order: 3
---

# Usage

Every inspection follows the same shape:

```bash
php artisan telescope:inspect [type flags] [filters] [output flags]
```

With no type flags you get an overview of entry counts per type and the flag for each one.

## Choosing entry types

| Flag | Inspects |
| --- | --- |
| `--requests` | Incoming HTTP requests |
| `--queries` | Database queries |
| `--exceptions` | Exceptions |
| `--jobs` | Queued jobs |
| `--commands` | Artisan command executions |
| `--schedule` | Scheduled task executions |
| `--cache` | Cache operations |
| `--dumps` | Variable dumps |
| `--events` | Application events |
| `--gates` | Gate and policy checks |
| `--http` | Outgoing HTTP client requests (not incoming traffic) |
| `--logs` | Log messages |
| `--mail` | Sent mail |
| `--models` | Eloquent model events |
| `--notifications` | Notifications |
| `--redis` | Redis commands |
| `--views` | Rendered views |
| `--batches` | Job batches |

Type flags combine. Pass several to inspect them in one run:

```bash
php artisan telescope:inspect --requests --queries --exceptions --last=1h
```

## Time windows

Use exactly one of these:

```bash
--last=15m                          # durations: 30s, 15m, 1h, 24h, 7d, 2w
--from=2026-08-01 --to=2026-08-02   # explicit window, either bound is optional
```

`--from` and `--to` accept anything `Carbon::parse()` understands (`2026-08-01`, `2026-08-01 14:30`, ISO 8601) and are read in the application timezone. `--last` sets only a lower bound; it cannot be combined with `--from` or `--to`. With no time option, all recorded entries are considered.

## Row filters

| Filter | Applies to |
| --- | --- |
| `--limit=50` | Max entries shown across the selected types (default 50, max 10000) |
| `--min-duration=500` | Entries with a duration of at least 500 ms (requests, queries, redis, http) |
| `--route=*orders*` | Request URI or controller action pattern (fnmatch against the stored value such as `/orders`, or plain-text substring) |
| `--method=GET,POST` | Comma-separated HTTP methods |
| `--status=500,404` | Comma-separated HTTP status codes |
| `--failed` | Only failed jobs |
| `--connection=mysql` | Database, queue, or Redis connection name |
| `--search=checkout` | Matches Telescope tags or raw entry content |

Filters apply only to entry types that have the matching field. Passing a filter that cannot match any selected type exits with code 2.

Examples:

```bash
php artisan telescope:inspect --requests --queries --last=1h --min-duration=500
php artisan telescope:inspect --jobs --failed --last=24h
php artisan telescope:inspect --http --status=500 --last=6h
php artisan telescope:inspect --queries --search="orders" --connection=mysql
```

Note that `--search` cannot use database indexes. On large tables, narrow it with a time window.

## Deep-diving a single entry

```bash
php artisan telescope:inspect --show=<uuid>
```

Prints every normalized field for that UUID. Sensitive fields require `--full`.

## Replaying a request or job

Every entry Telescope records during one request or job shares a batch id. Replay the whole lifecycle in recording order:

```bash
php artisan telescope:inspect --batch=<batch-id>
```

Batch mode ignores time windows and filters on purpose. Combine with `--json` to hand an agent or a teammate's script the complete story of one request.

## Watching live traffic

```bash
php artisan telescope:inspect --requests --queries --watch
php artisan telescope:inspect --exceptions --watch=5   # check every 5 seconds
```

The command starts from what is already stored and prints new entries as they arrive, one line each. Add `--ndjson` for machine-consumable lines. Press Ctrl+C to stop.

## Managing monitored tags

Monitored tags tell Telescope to keep entries that match them even when recording is paused:

```bash
php artisan telescope:monitor list
php artisan telescope:monitor add --tag="App\Jobs\*" --tag="Auth:id:7"
php artisan telescope:monitor remove --tag="SlowRequest"
```

## Output formats

| Flag | Behavior |
| --- | --- |
| *(default)* | Readable terminal output |
| `--json` | The JSON contract described in [json-output.md](json-output.md) |
| `--ndjson` | One compact JSON object per line |
| `--full` | Include sensitive values Telescope recorded |
| `--human` | Force human output even when an AI agent is detected |

## CI gates

`--fail-on` turns findings into exit code 3:

```bash
php artisan telescope:inspect --last=24h --fail-on=exceptions,failed-jobs
php artisan telescope:inspect --queries --fail-on=slow-queries --min-duration=1000 --last=1h
```

Supported checks: `exceptions`, `failed-jobs`, `slow-requests`, `slow-queries`. Slow checks use P95 route duration for requests (top 10 routes are evaluated) and individual query duration for queries. The threshold comes from `--min-duration` when given, otherwise from `slow_threshold_ms` in config.

A practical CI recipe pairs a short window with the check so old failures do not block every future build:

```bash
php artisan telescope:inspect --fail-on=exceptions,failed-jobs --last=15m
```

## AI agents

When the process runs under an AI coding agent, output switches to the JSON contract automatically (detected with [laravel/agent-detector](https://github.com/MrPunyapal/laravel-agent-detector): Claude Code, Cursor, OpenCode, Codex, Copilot, and others). The envelope includes an `agent` key with the detected name.

To force tables anyway, pass `--human`. To turn the behavior off completely, set `auto_json_for_agents` to `false` in the config file.

A [Laravel Boost](https://laravel.com/docs/boost) skill ships with the package (`resources/boost/skills/telescope-inspect`). Running `php artisan boost:install` in a project that uses this package installs it, so connected agents know when to reach for these commands and how to read the output.

## Exit codes

| Code | Meaning |
| --- | --- |
| 0 | Success, including "no issues" for `--fail-on` |
| 1 | Runtime failure (missing Telescope tables, unknown UUID) |
| 2 | Invalid usage (bad filter values or combinations) |
| 3 | Issues found via `--fail-on` |

Finding issues does not fail the command unless `--fail-on` is used.
