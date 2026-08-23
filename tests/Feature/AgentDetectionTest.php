<?php

use Illuminate\Support\Facades\Artisan;
use Laravel\AgentDetector\AgentDetector;

function actingAsAgent(?string $envVar = 'CLAUDECODE', string $value = '1'): void
{
    putenv($envVar.'='.$value);
}

function stopActingAsAgent(): void
{
    foreach (array_keys(AgentDetector::AGENT_ENV_VARS) as $var) {
        putenv($var);
    }

    putenv('AI_AGENT');
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
