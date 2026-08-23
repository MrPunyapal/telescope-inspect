# Usage

Every inspection starts the same way:

```bash
php artisan telescope:inspect [type flags] [filters] [output flags]
```

With no type flags you get an overview of entry counts per type, plus a hint about the flag for each type.

## Choosing entry types

| Flag | Inspects |
| --- | --- |
| `--requests` | Incoming HTTP requests |
| `--queries` | Database queries |
| `--exceptions` | Exceptions |
| `--jobs` | Queued jobs |
| `--commands` | Artisan command executions |
| `--schedule` | Scheduled task executions |
| `--cache` | Cache hits / misses / writes / deletes |
| `--dumps` | Variable dumps |
| `--events` | Application events |
| `--gates` | Gate and policy checks |
| `--http` | Outgoing HTTP client requests |
| `--logs` | Log messages |
| `--mail` | Sent mailables |
| `--models` | Eloquent model lifecycle events |
| `--notifications` | Notifications |
| `--redis` | Redis commands |
| `--views` | Rendered views |
| `--batches` | Queue batches |

Flags compose — pass several to inspect them in one run:

```bash
php artisan telescope:inspect --requests --queries --exceptions --last=1h
```

## Time windows

Exactly one of these:

```bash
--last=15m          # human durations: 30s, 15m, 1h, 24h, 7d, 2w
--from=2026-08-01 --to=2026-08-02   # explicit window (either bound optional)
```

`--from` / `--to` accept anything `Carbon::parse()` understands (`2026-08-01`, `2026-08-01 14:30`, ISO 8601) and are interpreted in your application timezone. `--last` cannot be combined with `--from`/`--to`.

## Row filters

| Filter | Applies to |
| --- | --- |
| `--limit=50` | Max entries shown across the selected types (default 50, max 10000) |
| `--min-duration=500` | Entries with a duration ≥ 500 ms (requests, queries, redis, http) |
| `--route=*orders*` | Request URI or controller action pattern (fnmatch against the stored value such as `/orders`, or plain-text substring) |
| `--method=GET,POST` | Comma-separated HTTP methods |
| `--status=500,404` | Comma-separated HTTP status codes |
| `--failed` | Only failed jobs (with `--jobs`) |
| `--connection=mysql` | Database / queue / Redis connection name |
| `--search=checkout` | Matches Telescope tags or raw entry content |

Filters compose naturally:

```bash
php artisan telescope:inspect --requests --queries --last=1h --min-duration=500 --limit=50
php artisan telescope:inspect --jobs --failed --last=24h
php artisan telescope:inspect --http --status=500 --last=6h
php artisan telescope:inspect --queries --search="orders" --connection=mysql
```

## Deep-diving a single entry

```bash
php artisan telescope:inspect --show=9d1f8a0e-... 
```

Prints every normalized field for that UUID — sensitive fields require `--full`. Pair with `--json` for machine consumption.

## Output formats

| Flag | Behavior |
| --- | --- |
| *(default)* | Human-readable terminal output |
| `--json` | The canonical JSON contract ([documented here](json-output.md)) |
| `--ndjson` | Newline-delimited JSON items — perfect for `jq` streaming |
| `--full` | Include sensitive values Telescope recorded (payloads, bindings, traces...) |

## CI gates

`--fail-on` turns findings into a non-zero exit code (3):

```bash
php artisan telescope:inspect --last=24h --fail-on=exceptions,failed-jobs
php artisan telescope:inspect --queries --fail-on=slow-queries --min-duration=1000
```

Supported checks: `exceptions`, `failed-jobs`, `slow-requests`, `slow-queries`. Slow checks use `--min-duration` when given, otherwise the configured `slow_threshold_ms`. Checked types are analyzed even if their type flag was not passed.

## Exit codes

| Code | Meaning |
| --- | --- |
| 0 | Success (including "no issues" for `--fail-on`) |
| 1 | Runtime failure (Telescope missing, UUID not found) |
| 2 | Invalid usage (bad filters, unknown options) |
| 3 | `--fail-on` checks found issues |

Finding issues does **not** fail the command unless `--fail-on` is used.
