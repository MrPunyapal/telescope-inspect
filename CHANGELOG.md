# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased](https://github.com/MrPunyapal/telescope-inspect/commits/main)

### Added

- `--pick`: after a human listing renders, an interactive Prompts selector offers every listed entry (time, type, summary, full file:line) and opens the full detail view for the chosen one; degrades to a clean no-op without an interactive terminal. Entry locations in human tables now truncate from the left, keeping the identifying file name and line instead of the path prefix.

### Fixed

- CI: restored matrix job creation (GitHub Actions silently skipped the job when `include:` introduced a new axis value; PHP 8.5 moved into the base matrix) and unblocked Composer's security-advisory resolution gate inside matrix installs so Laravel 11 cells can resolve again.
- Tests: console mocking disabled and PendingCommand usage replaced with real-output assertions, because Testbench's mocked OutputStyle starves `Artisan::output()` on older framework stacks, which had left the Laravel 11 lowest-dependency cells failing invisibly.

## 0.2.0 - 2026-08-24

Hardening release: correctness, privacy, and contract fixes from a full external audit. No new features; several behavior changes are intentional and documented below.

### Changed

- **Privacy**: request and HTTP-client `uri` now carries the path only; the query string moved to a gated `query_string` field (reset tokens, OAuth codes, API keys no longer leak into default output or route aggregation).
- **Privacy**: Redis entries show only the command verb and first argument (usually the key) by default; remaining parameters moved to gated `arguments`.
- **Privacy**: dump content (`--dumps`) is now redacted by default like other sensitive fields; entry-point links stay visible.
- **Privacy**: scheduled-task output is redacted by default.
- **JSON**: `summary.analysis_scoped_to_filters` renamed to `content_filters_present` - the old name implied analysis was filtered, which it never was.
- Machine modes (`--json`, `--ndjson`) now emit diagnostics on stderr as plain text so stdout stays parseable; human output is unchanged.
- A missing UUID now exits `1` in every output mode (JSON previously exited 0 with `"entry": null`). Same for a batch id that matches nothing.
- `--search` treats `%`, `_` and `\` as literal characters on every database driver (verified after fetch), matches terms stored JSON-escaped by Telescope (slashes, backslashes, non-ASCII), and documents its case semantics.

### Fixed

- Scan-truncation flag produced false positives when the table was smaller than the scan ceiling; `summary.scan.truncated` is now exact.
- `--batch` replays are bounded by `scan_limit` with truncation reported instead of loading an unbounded lifecycle into memory; bus batch ids resolve through their recorded entries.
- Watch mode no longer prints its start-up banner into piped NDJSON/JSON streams, honors `--full`, validates the interval argument, and drains bursts without waiting between polls.
- The recording of the inspection's own queries is stopped during runs, so results no longer drift from what Telescope's dashboard shows.
- Overview "failed jobs" tip never fired; failed jobs are counted directly from storage now.
- Exception entries expose Telescope's merged `occurrences` counter; dumps expose their entry-point links.
- Route aggregation groups by URI path rather than full URL with query string.
- `scan_limit` clamping is applied once at binding time so JSON provenance reports the effective value; `value_limit` is clamped too.

### Added

- Formal JSON Schema for the v1 contract: `schema/telescope-inspect-v1.schema.json`.
- `InspectFilters::fromArray()` - documented programmatic API that actually exists now, accepting semantic keys.
- `rows_scanned` counters in every analysis summary; violations hint shows the effective slow threshold.
- Human query listings show a `xN` repetition badge per repeated SQL pattern and remain visible under the analysis summary.
- CI: PHP 8.5 matrix cells, MySQL integration job, fail-fast disabled.

### Removed

- Composer root-only `minimum-stability: dev` / `prefer-stable` settings (not load-bearing).

## 0.1.1 - 2026-08-23

### Fixed

- Repaired character-encoding corruption in the README and documentation pages (garbled separator characters in sample output and stray mojibake).

## 0.1.0 - 2026-08-23

Initial release.

### Added

- `telescope:inspect` Artisan command with overview, per-type listings and deep-dive `--show=<uuid>`.
- Inspectable entry types: requests, queries, exceptions, jobs, commands, schedule, cache, dumps, events, gates, HTTP client, logs, mail, models, notifications, redis, views, batches.
- Filters: `--last`, `--from`/`--to`, `--limit`, `--min-duration`, `--route`, `--method`, `--status`, `--failed`, `--connection`, `--search`.
- Analysis summaries: route aggregation with avg/P95 durations and per-request query counts, slowest/most-frequent queries with likely N+1 detection, exception signature grouping, job failure tracking.
- Machine-readable output: versioned JSON envelope (`--json`) and newline-delimited items (`--ndjson`), sensitive-value redaction with `--full` opt-in.
- CI-oriented exit codes with `--fail-on=exceptions,failed-jobs,slow-requests,slow-queries`.
- Publishable configuration for redaction, truncation, scan bounds and the slow threshold.
- `--batch=<id>` replays every entry recorded during one request or job lifecycle, chronologically.
- `--watch[=seconds]` tails new entries as they arrive; pairs with `--ndjson` for streaming consumers.
- `telescope:monitor` command to list, add, and remove Telescope monitored tags.
- AI agent detection via `laravel/agent-detector`: when a command runs under an agent, output switches to JSON automatically (disable with `auto_json_for_agents`, override with `--human`). The envelope gains an `agent` key naming the detected agent.
- Laravel Boost skill (`resources/boost/skills/telescope-inspect`) so installed agents know how to drive the commands and read the JSON contract.

[Unreleased]: https://github.com/MrPunyapal/telescope-inspect/compare/v0.2.0...HEAD
[0.2.0]: https://github.com/MrPunyapal/telescope-inspect/compare/v0.1.1...v0.2.0
