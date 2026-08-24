<?php

namespace MrPunyapal\TelescopeInspect\Tests;

use Laravel\Telescope\TelescopeApplicationServiceProvider;
use Laravel\Telescope\TelescopeServiceProvider;
use MrPunyapal\TelescopeInspect\TelescopeInspectServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * Real buffered output for every Artisan::call: the JSON contract tests
     * parse stdout byte-for-byte, so Testbench's mocked console output
     * (which drops writes on some framework stacks) must stay off.
     */
    public $mockConsoleOutput = false;

    protected function getPackageProviders($app): array
    {
        return [
            TelescopeServiceProvider::class,
            TelescopeApplicationServiceProvider::class,
            TelescopeInspectServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('telescope.enabled', false);

        // CI's MySQL integration job points the testing connection at a real
        // server so SQL portability is exercised; everything else stays on
        // Testbench's sqlite default. Read the process environment directly:
        // phpunit.xml overrides DB_CONNECTION for every run.
        if (self::processEnv('TESTING_DB_DRIVER') === 'mysql') {
            $app['config']->set('database.connections.testing', [
                'driver' => 'mysql',
                'host' => self::processEnv('DB_HOST', '127.0.0.1'),
                'port' => self::processEnv('DB_PORT', '3306'),
                'database' => self::processEnv('DB_DATABASE', 'telescope_inspect_test'),
                'username' => self::processEnv('DB_USERNAME', 'root'),
                'password' => (string) self::processEnv('DB_PASSWORD', ''),
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
            ]);
        }
    }

    private static function processEnv(string $key, ?string $default = null): ?string
    {
        $value = getenv($key);

        return $value === false || $value === '' ? $default : $value;
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(realpath(__DIR__.'/../vendor/laravel/telescope/database/migrations'));
    }
}
