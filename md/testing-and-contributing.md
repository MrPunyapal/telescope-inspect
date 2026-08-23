---
order: 8
---

# Testing & Contributing

## Running the test suite

```bash
composer install
composer test        # Pest
composer analyse     # PHPStan / Larastan
composer lint        # Pint (check only)
composer check       # everything above + composer validate
```

The suite runs against Orchestra Testbench with an in-memory SQLite database. Tests insert **realistic Telescope rows** directly into Telescope's own migrations, with no mocking of the storage layer, so the actual SQL, normalization and analysis pipelines are exercised.

## Building the documentation site

Documentation lives in `md/` and is built into a static site with [docsmith](https://github.com/MrPunyapal/docsmith):

```bash
composer docs
```

Output lands in `docs/`.

## Contributing

1. Fork and clone the repository.
2. `composer install`.
3. Add tests for any behavior change; bug fixes need a regression test.
4. Keep the JSON contract stable: if you add fields, document them in `md/json-output.md` and keep `schema_version` unchanged unless it's a breaking change.
5. Run `composer check` before opening a pull request.
6. Pull requests should target `main` and keep a focused scope.

By contributing you agree your contributions are licensed under the MIT license.

## Reporting bugs

Please include: Laravel + Telescope versions, PHP version, the exact command you ran, and (with sensitive values scrubbed!) the JSON output if relevant.
