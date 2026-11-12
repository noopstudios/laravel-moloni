<?php

use Noop\Moloni\Settings\MoloniSettings;
use Spatie\LaravelSettings\SettingsContainer;

it('registers the settings with spatie when the Moloni provider registers first', function () {
    expect(app(SettingsContainer::class)->getSettingClasses()->all())->toContain(MoloniSettings::class)
        ->and(app(MoloniSettings::class))->toBe(app(MoloniSettings::class))
        ->and(app(MoloniSettings::class)->vies_unavailable)->toBe('charge');
});
