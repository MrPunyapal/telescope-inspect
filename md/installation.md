---
order: 2
---

# Installation

This package reads data recorded by [Laravel Telescope](https://laravel.com/docs/telescope), so install it in a project that already uses Telescope.

## 1. Require the package

```bash
composer require --dev mrpunyapal/telescope-inspect
```

The service provider is discovered automatically by Laravel.

## 2. Make sure Telescope works

If you have not set up Telescope yet:

```bash
composer require laravel/telescope --dev
php artisan telescope:install
php artisan migrate
```

## 3. Run it

```bash
php artisan telescope:inspect
```

You should see an overview of everything Telescope has recorded. The tool only reports what Telescope recorded, so if your database is empty, generate some traffic first.

## Requirements

- PHP 8.3 or newer
- Laravel 11, 12, or 13
- Laravel Telescope ^5.0 with its migrations run

## Updating

The JSON envelope follows a versioned contract (`schema_version`). Within schema version 1.x, existing keys keep their meaning and new keys may appear. Breaking changes to the JSON shape will bump the major version.
