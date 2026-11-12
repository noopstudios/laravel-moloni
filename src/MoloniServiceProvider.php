<?php

namespace Noop\Moloni;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Noop\Moloni\Console\CheckCommand;
use Noop\Moloni\Console\RecoverCommand;
use Noop\Moloni\Http\MoloniConnector;
use Noop\Moloni\Http\TokenStore;
use Noop\Moloni\Resolvers\ProductResolver;
use Noop\Moloni\Settings\MoloniSettings;
use Noop\Moloni\Tax\DefaultTaxRule;
use Noop\Moloni\Tax\TaxRule;

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
        $this->app->scoped(ProductResolver::class);
        $this->app->singleton(Moloni::class);
        $this->app->bind(TaxRule::class, function ($app) {
            $rule = config('moloni.tax_rule');

            return $app->make(is_string($rule) && $rule !== '' ? $rule : DefaultTaxRule::class);
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom([
            __DIR__.'/../database/migrations',
            __DIR__.'/../database/settings',
        ]);
        $this->loadJsonTranslationsFrom(__DIR__.'/../resources/lang');

        if ($this->app->runningInConsole()) {
            $this->commands([CheckCommand::class, RecoverCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('moloni:recover')->everyFiveMinutes()->withoutOverlapping();
        });

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
