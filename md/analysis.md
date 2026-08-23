# Analysis

Raw rows are rarely the answer. Telescope Inspect summarizes each analyzable type on top of the filtered window, bounded by `scan_limit` (default 5000 most recent matching entries).

## Requests

- Average and P95 duration across the window
- Status code distribution
- Per-route table (top 10 by P95 duration): request count, avg, P95, status breakdown
- **Average queries per route** — attributed by joining query entries to their request's flush batch
- The single slowest request with its UUID (deep-dive it with `--show`)

## Queries

- Total / average duration, number analyzed
- Slowest individual queries (top 10) with file:line of the caller
- Most time-consuming query patterns — identical SQL grouped by hash with execution counts and total time
- **Likely N+1**: identical SQL repeated many times within a single request batch

N+1 detection is explicitly a heuristic; the package reports *"likely"*, never certainty. A pattern is flagged when one normalized SQL statement executes at least 10 times inside one request batch; each offending SQL pattern is reported once, attributed to its worst batch.

```text
Likely N+1 (heuristic: identical SQL repeated within one request)
Executions  Avg ms  SQL                          Location
25          0.8     select * from items where... app/Http/Controllers/OrderController.php:32
```

## Exceptions

- Occurrence counts grouped into signatures (class + file + line)
- First/last seen timestamps per signature
- Latest occurrences with sample UUIDs for `--show` deep dives

## Jobs

- Status distribution (pending / processed / failed) and queue distribution
- Failed jobs with their exception messages
- Recurring failures ranked by count

## How aggregation is scoped

Analysis always respects your time window and runs over the **most recent matching entries** up to `scan_limit` — so on a huge production table you still get fast, meaningful answers instead of an unbounded scan. Listing (`--limit`) is independent of analysis scope.
