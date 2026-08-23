<?php

use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Process-level proof of the stream contract.
 *
 * In-process test runs buffer stdout and stderr together, so only a real
 * child process can prove that machine modes keep stdout parseable while
 * diagnostics go to stderr. These tests boot the committed workbench
 * application (a full Laravel skeleton with Telescope's tables migrated)
 * through vendor/bin/testbench and capture both streams separately.
 *
 * Tagged "process": excluded from the default suite run via phpunit.xml?
 * No - they are fast enough (<1s each) and prove the core contract, so
 * they always run; each skips itself when the workbench is unavailable.
 */
function workbenchAvailable(): bool
{
    $base = dirname(__DIR__, 2);

    return is_file($base.'/vendor/bin/testbench')
        && is_file($base.'/workbench/database/database.sqlite');
}

/**
 * Seed one raw row into the workbench database and run the inspect command
 * in a real process. Returns [exit code, stdout, stderr].
 *
 * @param  list<string>  $args
 * @return array{0: int, 1: string, 2: string}
 */
function inspectProcess(array $args, ?callable $seed = null): array
{
    $base = dirname(__DIR__, 2);
    $db = $base.'/workbench/database/database.sqlite';
    $pdo = new PDO('sqlite:'.$db);

    if ($seed !== null) {
        $seed($pdo);
    }

    $process = new Process(
        [PHP_BINARY, 'vendor/bin/testbench', 'telescope:inspect', ...$args],
        cwd: $base,
        timeout: 60,
        env: [
            // Pin both the default and Telescope storage connections so the
            // probe is independent of local .env drift.
            'DB_CONNECTION' => 'sqlite',
            'TELESCOPE_ENABLED' => 'false',
        ],
    );
    $process->run();

    return [$process->getExitCode() ?? -1, $process->getOutput(), $process->getErrorOutput()];
}

/**
 * Insert a request row with a marker secret directly via PDO.
 */
function seedRequestRow(PDO $pdo, string $uri, float $durationMs): void
{
    $uuid = (string) Str::uuid();
    $batch = (string) Str::uuid();
    $content = json_encode([
        'ip_address' => '127.0.0.1',
        'uri' => $uri,
        'method' => 'GET',
        'controller_action' => null,
        'middleware' => [],
        'headers' => [['authorization' => 'Bearer marker-secret-token']],
        'payload' => ['password' => 'marker-secret-value'],
        'session' => [],
        'response_headers' => [],
        'response_status' => 200,
        'response' => '',
        'duration' => $durationMs,
        'memory' => 12.5,
    ]);

    $statement = $pdo->prepare(
        'INSERT INTO telescope_entries (uuid, batch_id, family_hash, should_display_on_index, type, content, created_at)
         VALUES (:uuid, :batch_id, NULL, 1, :type, :content, :created_at)'
    );
    $statement->execute([
        'uuid' => $uuid,
        'batch_id' => $batch,
        'type' => 'request',
        'content' => $content,
        'created_at' => now()->format('Y-m-d H:i:s'),
    ]);
}

it('keeps json stdout strictly parseable with diagnostics on stderr in a real pipe', function () {
    if (! workbenchAvailable()) {
        $this->markTestSkipped('Workbench application is not available.');
    }

    [$exit, $stdout, $stderr] = inspectProcess(['--requests', '--json', '--limit=5'], function (PDO $pdo): void {
        seedRequestRow($pdo, 'https://localhost/process-check?token=abc', 42.0);
    });

    expect($exit)->toBe(0);

    // The whole stdout document parses; no ANSI anywhere. (A known
    // Orchestra Testbench filesystem notice may appear on Windows stderr
    // during skeleton boot; what matters is that OUR command emits no
    // diagnostics there.)
    /** @var array<string, mixed> $payload */
    $payload = json_decode(trim($stdout), true, 512, JSON_THROW_ON_ERROR);

    expect($payload['schema_version'])->toBe('1.0')
        ->and($stderr)->not->toContain('Telescope')
        ->and($stderr)->not->toContain('Issues found')
        ->and(preg_match('/\x1b\[[0-9;]*m/', $stdout))->toBe(0);
});

it('routes usage errors to stderr leaving stdout empty for machines', function () {
    if (! workbenchAvailable()) {
        $this->markTestSkipped('Workbench application is not available.');
    }

    [$exit, $stdout, $stderr] = inspectProcess([
        '--requests', '--queries', '--json', '--min-duration=abc',
    ]);

    expect($exit)->toBe(2)
        ->and(trim($stdout))->toBe('')
        ->and($stderr)->toContain('--min-duration');
});

it('reports missing uuids on stderr with exit code 1 in json mode', function () {
    if (! workbenchAvailable()) {
        $this->markTestSkipped('Workbench application is not available.');
    }

    [$exit, $stdout, $stderr] = inspectProcess(['--show='.(string) Str::uuid(), '--json']);

    expect($exit)->toBe(1)
        ->and(trim($stdout))->toBe('')
        ->and($stderr)->toContain('No Telescope entry found');
});

it('redacts sensitive values in real process output by default', function () {
    if (! workbenchAvailable()) {
        $this->markTestSkipped('Workbench application is not available.');
    }

    [, $stdout] = inspectProcess(['--requests', '--json', '--limit=5'], function (PDO $pdo): void {
        seedRequestRow($pdo, 'https://localhost/privacy-check', 20.0);
    });

    expect($stdout)->toContain('/privacy-check');
    expect($stdout)->not->toContain('marker-secret-value');
    expect($stdout)->not->toContain('marker-secret-token');
});
