<?php

namespace MrPunyapal\TelescopeInspect\Tests;

use Laravel\Telescope\TelescopeApplicationServiceProvider;
use Laravel\Telescope\TelescopeServiceProvider;
use MrPunyapal\TelescopeInspect\TelescopeInspectServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
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
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(realpath(__DIR__.'/../vendor/laravel/telescope/database/migrations'));
    }
}
