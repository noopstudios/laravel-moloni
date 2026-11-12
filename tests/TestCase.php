<?php

namespace Noop\Moloni\Tests;

use Noop\Moloni\MoloniServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Spatie\LaravelSettings\LaravelSettingsServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            LaravelSettingsServiceProvider::class,
            MoloniServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        // spatie/laravel-settings ships its migration as a stub, which loadMigrationsFrom() does not pick up.
        /** @var callable(): void $createSettingsTable */
        $createSettingsTable = [
            require __DIR__.'/../vendor/spatie/laravel-settings/database/migrations/create_settings_table.php.stub',
            'up',
        ];
        $createSettingsTable();

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
