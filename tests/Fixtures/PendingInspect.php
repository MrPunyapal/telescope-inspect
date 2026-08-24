<?php

namespace MrPunyapal\TelescopeInspect\Tests\Fixtures;

use Illuminate\Support\Facades\Artisan;

/**
 * Fluent replacement for Laravel's PendingCommand on top of real, unmocked
 * command execution. Testbench's mocked console output starves
 * Artisan::output() on some framework stacks, so the suite runs with
 * mockConsoleOutput disabled; this class keeps the historical
 * inspect()->expectsOutputToContain(...)->assertExitCode(...) call sites
 * readable while asserting against genuine stdout bytes.
 */
final class PendingInspect
{
    /** @var list<string> */
    private array $expectations = [];

    /** @var list<string> */
    private array $negatedExpectations = [];

    private ?int $expectedExit = null;

    private ?int $exit = null;

    private string $output = '';

    /**
     * @param  array<string, bool|int|string|null>  $options
     */
    public function __construct(private readonly array $options) {}

    public function expectsOutputToContain(string ...$needles): static
    {
        foreach ($needles as $needle) {
            $this->expectations[] = $needle;
        }

        $this->execute();

        return $this;
    }

    public function doesntExpectOutputToContain(string ...$needles): static
    {
        foreach ($needles as $needle) {
            $this->negatedExpectations[] = $needle;
        }

        $this->execute();

        return $this;
    }

    public function assertExitCode(int $exitCode): static
    {
        $this->expectedExit = $exitCode;

        $this->execute();

        return $this;
    }

    private function execute(): void
    {
        if ($this->exit !== null) {
            $this->verify();

            return;
        }

        $arguments = [];

        foreach ($this->options as $key => $value) {
            if ($value === false || $value === null) {
                continue;
            }

            $arguments['--'.$key] = $value;
        }

        $this->exit = Artisan::call('telescope:inspect', $arguments);
        $this->output = Artisan::output();

        $this->verify();
    }

    private function verify(): void
    {
        if ($this->expectedExit !== null) {
            expect($this->exit)->toBe($this->expectedExit);
        }

        foreach ($this->expectations as $needle) {
            expect($this->output)->toContain($needle);
        }

        foreach ($this->negatedExpectations as $needle) {
            expect($this->output)->not()->toContain($needle);
        }
    }
}
