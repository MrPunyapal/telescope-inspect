# Contributing

Thank you for considering a contribution to Telescope Inspect.

## Development setup

```bash
git clone https://github.com/MrPunyapal/telescope-inspect.git
cd telescope-inspect
composer install
```

The repository contains the Composer package (`src/`) plus its test suite (`tests/`). Tests run against Orchestra Testbench with an in-memory SQLite database and Telescope's real migrations.

A full Laravel application for manual experimentation lives in `workbench/` (gitignored). Create it with:

```bash
laravel new workbench --no-interaction --phpunit --database=sqlite --no-authentication --no-boost --no-node
cd workbench
composer require laravel/telescope:^5.22 --dev
php artisan telescope:install && php artisan migrate
composer config repositories.telescope-inspect path ../
composer require mrpunyapal/telescope-inspect --dev
```

## Quality gates

Every pull request must pass:

```bash
composer check
```

which runs `composer validate --strict`, Pint (style), PHPStan/Larastan (level 6) and the Pest suite.

## Guidelines

- Add tests for any behavior change; bug fixes need a regression test.
- Keep the JSON contract stable. Within schema version 1.x, existing fields must not change meaning. New fields are welcome; document them in `md/json-output.md`.
- Prefer deletion. The package's value is its small surface area.
- Respect privacy defaults. Never add sensitive values to default output paths.
- Verify normalizer field names against `workbench/vendor/laravel/telescope/src/Watchers` before changing them.

## Documentation

Docs live in `md/` and build into a static site with [docsmith](https://github.com/MrPunyapal/docsmith):

```bash
composer docs
```

Output lands in `docs/`.

## Reporting bugs

Include Laravel + Telescope versions, PHP version, the exact command you ran, and output with sensitive values scrubbed.

By contributing, you agree that your contributions are licensed under the MIT license.
