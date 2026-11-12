<?php

namespace Noop\Moloni;

use Illuminate\Support\ServiceProvider;
use Noop\Moloni\Http\MoloniConnector;
use Noop\Moloni\Http\TokenStore;

class MoloniServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/moloni.php', 'moloni');

        $this->app->singleton(TokenStore::class);
        $this->app->singleton(MoloniConnector::class, fn ($app) => new MoloniConnector($app->make(TokenStore::class)));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadJsonTranslationsFrom(__DIR__.'/../resources/lang');

        $this->publishes([
            __DIR__.'/../config/moloni.php' => config_path('moloni.php'),
        ], 'moloni-config');
    }
}
