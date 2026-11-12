<?php

namespace Noop\Moloni\Tests;

use Illuminate\Container\Container;
use Noop\Moloni\MoloniServiceProvider;
use Spatie\LaravelSettings\LaravelSettingsServiceProvider;
use Spatie\LaravelSettings\SettingsContainer;

/**
 * Registers the providers in package discovery order: noopstudios/* sorts before spatie/*,
 * so the Moloni provider registers before spatie builds its settings bindings.
 */
abstract class DiscoveryOrderTestCase extends TestCase
{
    protected function setUp(): void
    {
        // spatie caches its settings class list statically; earlier tests must not leak into this one.
        (new SettingsContainer(new Container))->clearCache();

        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        (new SettingsContainer(new Container))->clearCache();
    }

    protected function getPackageProviders($app): array
    {
        return [
            MoloniServiceProvider::class,
            LaravelSettingsServiceProvider::class,
        ];
    }
}
