<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use MrPunyapal\TelescopeInspect\Tests\Fixtures\EntryFactory;
use MrPunyapal\TelescopeInspect\Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test setup
|--------------------------------------------------------------------------
|
| Every test boots a Testbench application so Laravel helpers, the container
| and Telescope's storage are available in unit and feature tests alike.
|
*/

uses(TestCase::class)->in(__DIR__);

/**
 * Insert Telescope entries directly, bypassing watchers, so tests exercise
 * real storage queries against realistic rows.
 */
function telescopeFactory(): EntryFactory
{
    return new EntryFactory;
}

/**
 * Count rows in a Telescope table.
 */
function telescopeCount(string $table): int
{
    return (int) DB::table($table)->count();
}

/**
 * Run the inspect command with the given options and capture its result.
 *
 * @param  array<string, bool|int|string|null>  $options
 */
function inspect(array $options = []): PendingCommand
{
    $arguments = [];

    foreach ($options as $key => $value) {
        if ($value === false || $value === null) {
            continue;
        }

        $arguments['--'.$key] = $value;
    }

    /** @var PendingCommand $pending */
    $pending = test()->artisan('telescope:inspect', $arguments);

    return $pending;
}

/**
 * A valid UUID for fixture entries.
 */
function entryUuid(): string
{
    return (string) Str::uuid();
}

/**
 * Run the command in human mode and capture the rendered output.
 *
 * @param  array<string, bool|int|string|null>  $options
 */
function humanOutput(array $options = []): string
{
    Artisan::call('telescope:inspect', collect($options)
        ->mapWithKeys(fn ($value, $key): array => ['--'.$key => $value])
        ->filter()
        ->all());

    return Artisan::output();
}

/**
 * Run the inspect command with --json and return [exit code, decoded envelope].
 *
 * @param  array<string, bool|int|string|null>  $options
 * @return array{0: int, 1: array<string, mixed>}
 */
function inspectJsonWithExit(array $options = []): array
{
    $exit = Artisan::call('telescope:inspect', collect(array_merge(['json' => true], $options))
        ->mapWithKeys(fn ($value, $key): array => ['--'.$key => $value])
        ->filter()
        ->all());

    $raw = trim(Artisan::output());

    if ($raw === '') {
        return [$exit, []];
    }

    /** @var array<string, mixed> $decoded */
    $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

    return [$exit, $decoded];
}
