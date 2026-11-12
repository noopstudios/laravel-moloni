<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Noop\Moloni\Catalog\MoloniCatalog;
use Noop\Moloni\Enums\FailureType;

use function Pest\Laravel\travel;

beforeEach(function () {
    useFakeMoloniCredentials();
    Http::preventStrayRequests();
});

function catalogRequestsTo(string $endpoint): int
{
    return Http::recorded(fn (Request $request) => str_contains($request->url(), "/{$endpoint}/"))->count();
}

it('caches a catalog list for a day and flush clears it', function () {
    useMoloniSettings(['company_id' => 5]);
    Http::fake([
        '*/grant/*' => moloniGrant(),
        '*/paymentMethods/getAll/*' => [['payment_method_id' => 11, 'name' => 'Card']],
    ]);
    $catalog = app(MoloniCatalog::class);

    expect($catalog->paymentMethods()->all())->toBe([['payment_method_id' => 11, 'name' => 'Card']])
        ->and($catalog->paymentMethods()->all())->toBe([['payment_method_id' => 11, 'name' => 'Card']])
        ->and(catalogRequestsTo('paymentMethods/getAll'))->toBe(1);

    travel(23)->hours();
    $catalog->paymentMethods();
    expect(catalogRequestsTo('paymentMethods/getAll'))->toBe(1);

    travel(2)->hours();
    $catalog->paymentMethods();
    expect(catalogRequestsTo('paymentMethods/getAll'))->toBe(2);

    $catalog->flush();
    $catalog->paymentMethods();
    expect(catalogRequestsTo('paymentMethods/getAll'))->toBe(3);
});

it('reads each list from its endpoint, sending the company id only to company lists', function (string $method, string $endpoint, bool $companyScoped) {
    useMoloniSettings(['company_id' => 5]);
    Http::fake([
        '*/grant/*' => moloniGrant(),
        "*/{$endpoint}/*" => [['id' => 1]],
    ]);

    expect(app(MoloniCatalog::class)->{$method}()->all())->toBe([['id' => 1]]);

    Http::assertSent(fn (Request $request) => str_contains($request->url(), "/{$endpoint}/")
        && (! $companyScoped || $request['company_id'] === 5));
})->with([
    ['companies', 'companies/getAll', false],
    ['documentSets', 'documentSets/getAll', true],
    ['paymentMethods', 'paymentMethods/getAll', true],
    ['maturityDates', 'maturityDates/getAll', true],
    ['taxes', 'taxes/getAll', true],
    ['taxExemptions', 'taxExemptions/getAll', false],
    ['countries', 'countries/getAll', false],
    ['languages', 'languages/getAll', false],
    ['currencies', 'currencies/getAll', false],
    ['measurementUnits', 'measurementUnits/getAll', true],
    ['productCategories', 'productCategories/getAll', true],
]);

it('reads only the top-level product categories', function () {
    useMoloniSettings(['company_id' => 5]);
    Http::fake([
        '*/grant/*' => moloniGrant(),
        '*/productCategories/getAll/*' => [['category_id' => 3, 'name' => 'Services']],
    ]);

    app(MoloniCatalog::class)->productCategories();

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/productCategories/getAll/')
        && $request['parent_id'] === 0);
});

it('reads global lists without a company configured', function () {
    Http::fake([
        '*/grant/*' => moloniGrant(),
        '*/countries/getAll/*' => [['country_id' => 1, 'iso_3166_1' => 'PT']],
    ]);

    expect(app(MoloniCatalog::class)->countries())->toHaveCount(1);
});

it('refuses company lists with Validation when no company is configured, without calling Moloni', function () {
    Http::fake();

    $e = catchMoloniException(fn () => app(MoloniCatalog::class)->documentSets());

    expect($e->type)->toBe(FailureType::Validation)
        ->and($e->getMessage())->toContain('company');
    Http::assertNothingSent();
});

it('keeps company lists apart per company', function () {
    useMoloniSettings(['company_id' => 5]);
    Http::fake([
        '*/grant/*' => moloniGrant(),
        '*/taxes/getAll/*' => fn (Request $request) => Http::response([['tax_id' => $request['company_id'] * 10]]),
    ]);
    $catalog = app(MoloniCatalog::class);

    expect($catalog->taxes()->all())->toBe([['tax_id' => 50]]);

    useMoloniSettings(['company_id' => 6]);

    expect($catalog->taxes()->all())->toBe([['tax_id' => 60]])
        ->and(catalogRequestsTo('taxes/getAll'))->toBe(2);
});

it('does not cache a failed read', function () {
    useMoloniSettings(['company_id' => 5]);
    Http::fake([
        '*/grant/*' => moloniGrant(),
        '*/documentSets/getAll/*' => Http::sequence()
            ->push('', 500)
            ->push([['document_set_id' => 21, 'name' => 'A']]),
    ]);
    $catalog = app(MoloniCatalog::class);

    $e = catchMoloniException(fn () => $catalog->documentSets());

    expect($e->type)->toBe(FailureType::Transient)
        ->and($catalog->documentSets()->all())->toBe([['document_set_id' => 21, 'name' => 'A']]);
});

it('resolves a country id from its ISO code and fails with Validation for an unknown one', function () {
    Http::fake([
        '*/grant/*' => moloniGrant(),
        '*/countries/getAll/*' => [
            ['country_id' => 1, 'iso_3166_1' => 'PT', 'name' => 'Portugal'],
            ['country_id' => 70, 'iso_3166_1' => 'ES', 'name' => 'Espanha'],
        ],
    ]);
    $catalog = app(MoloniCatalog::class);

    expect($catalog->countryId('PT'))->toBe(1)
        ->and($catalog->countryId('es'))->toBe(70);

    $e = catchMoloniException(fn () => $catalog->countryId('ZZ'));

    expect($e->type)->toBe(FailureType::Validation)
        ->and($e->getMessage())->toContain('ZZ');
});

it('uses Portuguese for Portugal and English for every other country', function () {
    Http::fake([
        '*/grant/*' => moloniGrant(),
        '*/languages/getAll/*' => [
            ['language_id' => 1, 'code' => 'PT', 'name' => 'Português'],
            ['language_id' => 2, 'code' => 'EN', 'name' => 'Inglês'],
            ['language_id' => 3, 'code' => 'ES', 'name' => 'Espanhol'],
        ],
    ]);
    $catalog = app(MoloniCatalog::class);

    expect($catalog->languageIdFor('PT'))->toBe(1)
        ->and($catalog->languageIdFor('pt'))->toBe(1)
        ->and($catalog->languageIdFor('ES'))->toBe(2)
        ->and($catalog->languageIdFor('FR'))->toBe(2);
});

it('fails with Validation when Moloni has no matching language', function () {
    Http::fake([
        '*/grant/*' => moloniGrant(),
        '*/languages/getAll/*' => [['language_id' => 1, 'code' => 'PT']],
    ]);

    expect(catchMoloniException(fn () => app(MoloniCatalog::class)->languageIdFor('DE'))->type)
        ->toBe(FailureType::Validation);
});
