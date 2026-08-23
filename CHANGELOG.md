# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased](https://github.com/MrPunyapal/telescope-inspect/commits/main/compare/v0.1.1...HEAD)

Nothing yet.

## 0.1.1 - 2026-08-23

Initial release. (Supersedes the briefly published 0.1.0 tag, which carried an incorrect PHP constraint.)

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

## [v0.1.1](https://github.com/MrPunyapal/telescope-inspect/commits/main/compare/v0.1.0...v0.1.1) - 2026-08-23

Initial usable release. Supersedes the briefly published v0.1.0 tag, which carried an incorrect PHP constraint.

Same feature set as documented in the changelog:

- telescope:inspect: overview, per-type listings, filters, --batch=<id> lifecycle replay, --watch live tail, --show=<uuid>

- Analysis: slow routes with P95 and queries-per-request, repeated SQL patterns, likely N+1 evidence, exception signatures, job failure tracking
- Output: human tables, versioned JSON envelope (--json), NDJSON (--ndjson), sensitive fields redacted unless --full
- AI agents: automatic JSON via laravel/agent-detector, Laravel Boost skill included
- CI gates: --fail-on with exit codes 0/1/2/3

Requires PHP 8.3+, Laravel 11/12/13, Telescope ^5.0.

## [v0.1.0](https://github.com/MrPunyapal/telescope-inspect/commits/main/compare/main...v0.1.0) - 2026-08-23

Initial release of Telescope Inspect.

### What you get

\ash
composer require --dev mrpunyapal/telescope-inspect
php artisan telescope:inspect --requests --last=1h
\

### Highlights

- Inspect all 18 Telescope entry types with filters: time windows, duration, route, method, status, connection, free-text search
- Analysis summaries: slow routes with P95 and queries-per-request, repeated SQL patterns, likely N+1 detection, exception signatures, job failure tracking
- Replay a full request or job lifecycle with --batch=<id>\
- Watch live traffic with --watch\
- Manage monitored tags with \	elescope:monitor list|add|remove\
- Versioned JSON envelope (--json) and NDJSON (--ndjson), sensitive fields redacted unless --full\
- Automatic JSON for AI coding agents via laravel/agent-detector, plus a Laravel Boost skill
- CI gates: --fail-on=exceptions,failed-jobs,slow-requests,slow-queries\ with documented exit codes

Requires PHP 8.3+, Laravel 11/12/13, Telescope ^5.0.
