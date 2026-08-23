# Installation

Telescope Inspect is a development companion to [Laravel Telescope](https://laravel.com/docs/telescope). Install it in a project that already uses Telescope.

## 1. Require the package

```bash
composer require --dev mrpunyapal/telescope-inspect
```

The service provider is discovered automatically by Laravel — no manual registration needed.

## 2. Make sure Telescope works

If you haven't set up Telescope yet:

```bash
composer require laravel/telescope --dev
php artisan telescope:install
php artisan migrate
```

## 3. Run it

```bash
php artisan telescope:inspect
```

You should see an overview of everything Telescope has recorded. If your database is empty, generate some traffic first — the tool only reports what Telescope recorded.

## Requirements

- PHP ^8.2
- Laravel 11, 12 or 13
- Laravel Telescope ^5.0 with its migrations run

## Updating

The JSON output contract (`schema_version`) follows [semver](json-output.md): fields may be added over time, existing fields keep their meaning within a major schema version.
