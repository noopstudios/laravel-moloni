<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Noop\Moloni\Http\MoloniConnector;
use Noop\Moloni\Settings\MoloniSettings;

it('creates the moloni settings with their defaults when migrating', function () {
    $settings = app(MoloniSettings::class);

    expect($settings->company_id)->toBeNull()
        ->and($settings->document_sets)->toBe([])
        ->and($settings->payment_methods)->toBe([])
        ->and($settings->maturity_dates)->toBe([])
        ->and($settings->default_category_id)->toBeNull()
        ->and($settings->default_unit_id)->toBeNull()
        ->and($settings->default_product_id)->toBeNull()
        ->and($settings->tax_overrides)->toBe([])
        ->and($settings->exemption_reasons)->toBe(['eu_business' => 'M40', 'non_eu_business' => 'M40', 'non_eu_consumer' => 'M44'])
        ->and($settings->vies_unavailable)->toBe('charge');
});

it('registers the settings migration so the host app migrates it', function () {
    $paths = collect(app('migrator')->paths())->map(fn (string $path) => realpath($path));

    expect($paths)->toContain(realpath(__DIR__.'/../../../database/settings'));
});

it('registers the settings class with spatie and binds it per scope', function () {
    expect(config('settings.settings'))->toContain(MoloniSettings::class)
        ->and(app(MoloniSettings::class))->toBe(app(MoloniSettings::class));
});

it('sends the configured company id with every Moloni request', function () {
    useFakeMoloniCredentials();
    Http::preventStrayRequests();
    Http::fake([
        '*/grant/*' => moloniGrant(),
        '*/customers/getAll/*' => Http::response([]),
    ]);
    $settings = app(MoloniSettings::class);
    $settings->company_id = 77;
    $settings->save();

    app(MoloniConnector::class)->read('customers/getAll');

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'customers/getAll')
        && $request['company_id'] === 77);
});

it('resolves no company id when the settings row is missing', function () {
    DB::table('settings')->where('group', 'moloni')->where('name', 'company_id')->delete();
    app()->forgetScopedInstances();

    expect(app(MoloniSettings::class)->companyIdOrNull())->toBeNull();
});

it('resolves no company id when the settings table does not exist', function () {
    Schema::drop('settings');
    app()->forgetScopedInstances();

    expect(app(MoloniSettings::class)->companyIdOrNull())->toBeNull();
});

it('omits the company id from requests when the settings table does not exist', function () {
    useFakeMoloniCredentials();
    Http::preventStrayRequests();
    Http::fake([
        '*/grant/*' => moloniGrant(),
        '*/countries/getAll/*' => Http::response([]),
    ]);
    Schema::drop('settings');
    app()->forgetScopedInstances();

    app(MoloniConnector::class)->read('countries/getAll');

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'countries/getAll')
        && ! array_key_exists('company_id', $request->data()));
});
