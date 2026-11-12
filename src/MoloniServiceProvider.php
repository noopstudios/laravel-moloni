<?php

namespace Noop\Moloni;

use Illuminate\Support\ServiceProvider;

class MoloniServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/moloni.php', 'moloni');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadJsonTranslationsFrom(__DIR__.'/../resources/lang');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/moloni.php' => config_path('moloni.php'),
            ], 'moloni-config');
        }
    }
}
