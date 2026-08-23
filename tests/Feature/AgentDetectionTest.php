<?php

use Illuminate\Support\Facades\Artisan;
use Laravel\AgentDetector\AgentDetector;

function actingAsAgent(?string $envVar = 'CLAUDECODE', string $value = '1'): void
{
    putenv($envVar.'='.$value);
}

function stopActingAsAgent(): void
{
    // Mirrors AgentDetector's env-var list; the constant is protected upstream.
    foreach ([
        'AI_AGENT', 'CURSOR_AGENT', 'GEMINI_CLI', 'CODEX_SANDBOX', 'CODEX_CI',
        'CODEX_THREAD_ID', 'AUGMENT_AGENT', 'OPENCODE_CLIENT', 'OPENCODE',
        'AMP_CURRENT_THREAD_ID', 'CLAUDECODE', 'CLAUDE_CODE', 'CLAUDE_CODE_IS_COWORK',
        'REPL_ID', 'COPILOT_MODEL', 'COPILOT_ALLOW_ALL', 'COPILOT_GITHUB_TOKEN',
        'COPILOT_CLI', 'ANTIGRAVITY_AGENT', 'PI_CODING_AGENT', 'KIRO_AGENT_PATH',
    ] as $var) {
        putenv($var);
    }
}

beforeEach(fn () => stopActingAsAgent());
afterEach(fn () => stopActingAsAgent());

it('returns the json contract automatically when an ai agent is detected', function () {
    actingAsAgent();

    $factory = telescopeFactory();
    $factory->exception('RuntimeException', 'detected agent output');
    $factory->persist();

    Artisan::call('telescope:inspect', ['--exceptions' => true]);

    /** @var array<string, mixed> $payload */
    $payload = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);

    expect($payload['agent'])->toBe('claude')
        ->and($payload['items'])->toHaveCount(1);
});

it('reports the agent name from the AI_AGENT variable', function () {
    actingAsAgent('AI_AGENT', 'claude-code');

    telescopeFactory()->request(['uri' => 'http://localhost/agent']);
    telescopeFactory()->persist();

    Artisan::call('telescope:inspect', ['--requests' => true]);

    /** @var array<string, mixed> $payload */
    $payload = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);

    expect($payload['agent'])->toBe('claude');
});

it('keeps human output when the human flag is passed', function () {
    actingAsAgent();

    $factory = telescopeFactory();
    $factory->request(['uri' => 'http://localhost/table-please']);
    $factory->persist();

    Artisan::call('telescope:inspect', ['--requests' => true, '--human' => true]);

    $output = Artisan::output();

    expect(str_starts_with(trim($output), '{'))->toBeFalse()
        ->and($output)->toContain('/table-please');
});

it('can disable automatic agent switching in config', function () {
    actingAsAgent();
    config(['telescope-inspect.auto_json_for_agents' => false]);

    $factory = telescopeFactory();
    $factory->request(['uri' => 'http://localhost/plain']);
    $factory->persist();

    inspect(['requests' => true])
        ->expectsOutputToContain('Requests · showing 1 of 1')
        ->assertExitCode(0);
});

it('respects explicit ndjson for agents without setting the agent key', function () {
    actingAsAgent();

    $factory = telescopeFactory();
    $factory->request(['uri' => 'http://localhost/stream']);
    $factory->persist();

    Artisan::call('telescope:inspect', ['--ndjson' => true, '--requests' => true]);

    $decoded = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);

    expect($decoded)->toHaveKey('uuid');
});

it('stays human for people even with the config enabled', function () {
    $factory = telescopeFactory();
    $factory->request(['uri' => 'http://localhost/human-eye']);
    $factory->persist();

    inspect(['requests' => true])
        ->expectsOutputToContain('Requests · showing 1 of 1')
        ->assertExitCode(0);
});
