<?php

namespace Elmekadem\ArchitectorAdmin\Providers;

use Illuminate\Support\ServiceProvider;

class ArchitectorServiceProvider extends ServiceProvider
{
    private const COMMANDS = [
        'Elmekadem\\ArchitectorAdmin\\Console\\Commands\\AdminSetup',
    ];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            dirname(__DIR__).'/config/architector.php',
            'architector'
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $packageRoot = dirname(__DIR__, 2);
        $sourceRoot = dirname(__DIR__);

        // Register commands (only if they exist)
        if ($this->app->runningInConsole()) {
            $commands = array_filter(self::COMMANDS, function ($commandClass) {
                return class_exists($commandClass);
            });

            if (!empty($commands)) {
                $this->commands($commands);
            }
        }

        // Publish views
        $this->publishes([
            $packageRoot.'/resources/views' => resource_path('views/vendor/architector'),
        ], 'architector-views');

        // Publish stubs
        $this->publishes([
            $packageRoot.'/resources/stubs' => base_path('stubs/vendor/architector'),
        ], 'architector-stubs');

        // Publish config
        $this->publishes([
            $sourceRoot.'/config/architector.php' => config_path('architector.php'),
        ], 'architector-config');

        // Publish migrations
        $this->publishes([
            $packageRoot.'/database/migrations' => database_path('migrations'),
        ], 'architector-migrations');

        // Publish everything with one short tag
        $this->publishes([
            $packageRoot.'/resources/views' => resource_path('views/vendor/architector'),
            $packageRoot.'/resources/stubs' => base_path('stubs/vendor/architector'),
            $sourceRoot.'/config/architector.php' => config_path('architector.php'),
            $packageRoot.'/database/migrations' => database_path('migrations'),
        ], 'architector');

        // Load views
        $this->loadViewsFrom($packageRoot.'/resources/views', 'architector');

        // Load migrations
        $this->loadMigrationsFrom($packageRoot.'/database/migrations');
    }
}
