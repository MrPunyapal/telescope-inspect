# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-08-23

Initial release.

### Added

- `telescope:inspect` Artisan command with overview, per-type listings and deep-dive `--show=<uuid>`.
- Inspectable entry types: requests, queries, exceptions, jobs, commands, schedule, cache, dumps, events, gates, HTTP client, logs, mail, models, notifications, redis, views, batches.
- Filters: `--last`, `--from`/`--to`, `--limit`, `--min-duration`, `--route`, `--method`, `--status`, `--failed`, `--connection`, `--search`.
- Analysis summaries: route aggregation with avg/P95 durations and per-request query counts, slowest/most-frequent queries with likely N+1 detection, exception signature grouping, job failure tracking.
- Machine-readable output: versioned JSON envelope (`--json`) and newline-delimited items (`--ndjson`), sensitive-value redaction with `--full` opt-in.
- CI-oriented exit codes with `--fail-on=exceptions,failed-jobs,slow-requests,slow-queries`.
- Publishable configuration for redaction, truncation, scan bounds and the slow threshold.

[Unreleased]: https://github.com/MrPunyapal/telescope-inspect/compare/0.1.0...HEAD
[0.1.0]: https://github.com/MrPunyapal/telescope-inspect/releases/tag/0.1.0
