# AGENTS.md

Guidance for AI coding agents working on this repository.

## What this package is

`mrpunyapal/telescope-inspect` provides two Artisan commands, `telescope:inspect` and `telescope:monitor`, that read Laravel Telescope's local storage tables (`telescope_entries`) and present them as human-readable terminal tables or machine-readable JSON. It never writes to Telescope data and makes no network requests.

## Commands

```bash
composer test       # Pest suite against Testbench and real Telescope migrations
composer analyse    # PHPStan / Larastan level 6
composer lint       # Pint (check only)
composer fix        # Pint (apply fixes)
composer docs       # rebuild the documentation site into docs/
composer check      # validate + lint + analyse + test
```

## Architecture

The pipeline is strictly one-directional:

1. `InspectFilters` (src/Filters) parses CLI flags into a value object.
2. `EntryRepository` (src/Query) runs bounded SQL against `telescope_entries` (newest-first, capped by `scan_limit`).
3. `ContentNormalizer` (src/Normalization) turns raw Telescope `content` JSON into stable per-type fields.
4. Analyzers (src/Analysis) aggregate requests, queries, exceptions and jobs.
5. `InspectionResult` carries everything; `HumanPresenter` and `JsonPresenter` (src/Output) render it.

See [md/architecture.md](md/architecture.md) for the full reference.

## Rules

- The `--json` envelope is a public contract. Within schema version `1.x`, never rename or remove keys; additions are allowed but must be documented in `md/json-output.md` and noted in `CHANGELOG.md`.
- Tests must exercise Telescope's real migrations through Orchestra Testbench (`tests/TestCase.php`). Do not mock the storage layer.
- Source and docs stay ASCII-safe except the intentional separators produced by `HumanPresenter` (U+00B7 middle dot, U+00D7 multiplication sign). No em dashes or smart quotes anywhere.
- Documentation lives in `md/*.md` and builds with docsmith. Frontmatter `order:` controls sidebar position; keep `index.md` at order 1.
- The changelog is maintained manually in Keep a Changelog format. There is intentionally no automation committing to it.

## Release process

1. Move the changelog's Unreleased content under a new `## X.Y.Z - YYYY-MM-DD` heading.
2. Commit and push `main`.
3. Only then create and push the annotated tag `vX.Y.Z` pointing at the pushed commit. Never move a tag after pushing; Packagist treats versions as immutable.
4. `gh release create vX.Y.Z --title vX.Y.Z --notes-file <file>` with a short summary.
