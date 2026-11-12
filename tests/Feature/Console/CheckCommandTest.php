<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Noop\Moloni\Settings\SettingsCheck;

beforeEach(function () {
    useFakeMoloniCredentials();
    Http::preventStrayRequests();
});

function configureEverything(): void
{
    useMoloniSettings([
        'company_id' => 5,
        'document_sets' => ['invoice_receipt' => 21, 'credit_note' => 22],
        'payment_methods' => ['card' => 31],
        'maturity_dates' => [30 => 41],
        'default_category_id' => 51,
        'default_unit_id' => 61,
        'default_product_id' => 71,
        'tax_overrides' => ['23' => 81],
        'exemption_reasons' => ['eu_business' => 'M40', 'non_eu_business' => 'M40', 'non_eu_consumer' => 'M44'],
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function fakeMoloniCatalog(array $overrides = []): void
{
    Http::fake([
        '*/grant/*' => moloniGrant(),
        '*/companies/getAll/*' => [['company_id' => 5, 'name' => 'Example Shop, Lda.']],
        '*/documentSets/getAll/*' => [['document_set_id' => 21, 'name' => 'A'], ['document_set_id' => 22, 'name' => 'B']],
        '*/paymentMethods/getAll/*' => [['payment_method_id' => 31, 'name' => 'Card']],
        '*/maturityDates/getAll/*' => [['maturity_date_id' => 41, 'days' => 30]],
        '*/productCategories/getAll/*' => [['category_id' => 51, 'name' => 'Services']],
        '*/measurementUnits/getAll/*' => [['unit_id' => 61, 'name' => 'Unit']],
        '*/products/getOne/*' => ['product_id' => 71, 'name' => 'Fee'],
        '*/taxes/getAll/*' => [['tax_id' => 81, 'value' => 23, 'fiscal_zone' => 'PT']],
        '*/taxExemptions/getAll/*' => [['code' => 'M40'], ['code' => 'M44']],
        ...$overrides,
    ]);
}

it('check passes when every configured id exists in Moloni', function () {
    configureEverything();
    fakeMoloniCatalog();

    expect(app(SettingsCheck::class)->problems())->toBe([]);

    expect(Artisan::call('moloni:check'))->toBe(0)
        ->and(Artisan::output())->toContain('valid');
});

it('check fails and names the missing document set', function () {
    configureEverything();
    fakeMoloniCatalog(['*/documentSets/getAll/*' => [['document_set_id' => 21, 'name' => 'A']]]);

    expect(Artisan::call('moloni:check'))->toBe(1)
        ->and(Artisan::output())->toContain('document set');

    expect(app(SettingsCheck::class)->problems())
        ->toBe(['The credit_note document set 22 does not exist in Moloni.']);
});

it('reports every configured id missing from Moloni', function () {
    configureEverything();
    fakeMoloniCatalog([
        '*/paymentMethods/getAll/*' => Http::response([]),
        '*/maturityDates/getAll/*' => Http::response([]),
        '*/productCategories/getAll/*' => Http::response([]),
        '*/measurementUnits/getAll/*' => Http::response([]),
        '*/taxes/getAll/*' => Http::response([]),
        '*/taxExemptions/getAll/*' => [['code' => 'M40']],
    ]);

    $problems = app(SettingsCheck::class)->problems();

    expect($problems)->toHaveCount(6)
        ->and(implode("\n", $problems))
        ->toContain('payment method')
        ->toContain('maturity date')
        ->toContain('category')
        ->toContain('unit')
        ->toContain('tax 81')
        ->toContain('M44');
});

it('rejects a default product when Moloni returns a different product for its id', function () {
    configureEverything();
    fakeMoloniCatalog(['*/products/getOne/*' => ['product_id' => 9999, 'name' => 'Something else']]);

    expect(app(SettingsCheck::class)->problems())->toHaveCount(1)
        ->and(app(SettingsCheck::class)->problems()[0])->toContain('product');

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/products/getOne/')
        && $request['product_id'] === 71);
});

it('rejects a default product when Moloni returns nothing for its id', function () {
    configureEverything();
    fakeMoloniCatalog(['*/products/getOne/*' => Http::response([])]);

    expect(app(SettingsCheck::class)->problems())->toHaveCount(1);
});

it('stops at the login when Moloni refuses the credentials', function () {
    configureEverything();
    Http::fake(['*/grant/*' => Http::response(['error' => 'invalid_grant'], 400)]);

    $problems = app(SettingsCheck::class)->problems();

    expect($problems)->toHaveCount(1)
        ->and($problems[0])->toContain('log in');
});

it('stops when no company is configured', function () {
    fakeMoloniCatalog();

    $problems = app(SettingsCheck::class)->problems();

    expect($problems)->toHaveCount(1)
        ->and($problems[0])->toContain('company');
});

it('stops when the configured company does not exist', function () {
    configureEverything();
    fakeMoloniCatalog(['*/companies/getAll/*' => [['company_id' => 6]]]);

    $problems = app(SettingsCheck::class)->problems();

    expect($problems)->toHaveCount(1)
        ->and($problems[0])->toContain('company 5');
});

it('reports a list that cannot be read and still checks the others', function () {
    configureEverything();
    fakeMoloniCatalog([
        '*/paymentMethods/getAll/*' => Http::response('', 500),
        '*/taxes/getAll/*' => Http::response([]),
    ]);

    $problems = app(SettingsCheck::class)->problems();

    expect($problems)->toHaveCount(2)
        ->and(implode("\n", $problems))->toContain('paymentMethods/getAll')->toContain('tax 81');
});

it('checks against fresh lists rather than cached ones', function () {
    configureEverything();
    fakeMoloniCatalog();
    app(SettingsCheck::class)->problems();
    app(SettingsCheck::class)->problems();

    expect(Http::recorded(fn (Request $request) => str_contains($request->url(), '/taxes/getAll/'))->count())->toBe(2);
});
