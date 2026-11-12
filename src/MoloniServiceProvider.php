<?php

namespace Noop\Moloni;

use Illuminate\Support\ServiceProvider;
use Noop\Moloni\Console\CheckCommand;
use Noop\Moloni\Http\MoloniConnector;
use Noop\Moloni\Http\TokenStore;
use Noop\Moloni\Settings\MoloniSettings;

class MoloniServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/moloni.php', 'moloni');
        $this->registerSettings();

        $this->app->singleton(TokenStore::class);
        $this->app->singleton(MoloniConnector::class, fn ($app) => new MoloniConnector(
            $app->make(TokenStore::class),
            fn () => $app->make(MoloniSettings::class)->companyIdOrNull(),
        ));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom([
            __DIR__.'/../database/migrations',
            __DIR__.'/../database/settings',
        ]);
        $this->loadJsonTranslationsFrom(__DIR__.'/../resources/lang');

        if ($this->app->runningInConsole()) {
            $this->commands([CheckCommand::class]);
        }

        $this->publishes([
            __DIR__.'/../config/moloni.php' => config_path('moloni.php'),
        ], 'moloni-config');
    }

    /**
     * Registers the settings class without the host app editing config/settings.php. spatie may
     * already have built its bindings before this provider registers, so bind it here as well.
     */
    private function registerSettings(): void
    {
        $registered = config('settings.settings', []);
        $registered = is_array($registered) ? $registered : [];

        if (! in_array(MoloniSettings::class, $registered, true)) {
            config(['settings.settings' => [...$registered, MoloniSettings::class]]);
        }

        if (! $this->app->bound(MoloniSettings::class)) {
            $this->app->scoped(MoloniSettings::class);
        }
    }
}
