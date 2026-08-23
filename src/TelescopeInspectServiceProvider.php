<?php

namespace MrPunyapal\TelescopeInspect;

use Illuminate\Support\ServiceProvider;
use MrPunyapal\TelescopeInspect\Commands\InspectCommand;
use MrPunyapal\TelescopeInspect\Commands\MonitorCommand;
use MrPunyapal\TelescopeInspect\Normalizers\ContentNormalizer;
use MrPunyapal\TelescopeInspect\Query\EntryRepository;

class TelescopeInspectServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/telescope-inspect.php', 'telescope-inspect');

        $this->app->singleton(ContentNormalizer::class, function (): ContentNormalizer {
            return new ContentNormalizer(
                valueLimit: (int) config('telescope-inspect.value_limit', 1000),
            );
        });

        $this->app->singleton(EntryRepository::class, function ($app): EntryRepository {
            return new EntryRepository(
                normalizer: $app->make(ContentNormalizer::class),
                scanLimit: max(100, min(50000, (int) config('telescope-inspect.scan_limit', 5000))),
            );
        });

        $this->app->singleton(TelescopeInspector::class, function ($app): TelescopeInspector {
            return new TelescopeInspector(
                repository: $app->make(EntryRepository::class),
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                InspectCommand::class,
                MonitorCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/telescope-inspect.php' => config_path('telescope-inspect.php'),
            ], 'telescope-inspect-config');
        }
    }
}
