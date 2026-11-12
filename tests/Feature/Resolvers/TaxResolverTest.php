<?php

use Illuminate\Support\Facades\Http;
use Noop\Moloni\Data\InvoiceLine;
use Noop\Moloni\Enums\FailureType;
use Noop\Moloni\Resolvers\TaxResolver;

beforeEach(function () {
    useFakeMoloniCredentials();
    Http::preventStrayRequests();
    useMoloniSettings(['company_id' => 5]);
});

/**
 * @param  list<array<string, mixed>>  $taxes
 */
function fakeTaxCatalog(array $taxes): void
{
    Http::fake([
        '*/grant/*' => moloniGrant(),
        '*/taxes/getAll/*' => $taxes,
        '*/taxExemptions/getAll/*' => [
            ['code' => 'M07', 'at_code' => 'M07', 'name' => 'Isento artigo 9.º'],
            ['code' => 'M99X', 'at_code' => 'M99', 'name' => 'Não sujeito'],
            ['code' => 'M40', 'name' => 'Autoliquidação'],
        ],
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function vatRow(int $taxId, float $value, array $overrides = []): array
{
    return [
        'tax_id' => $taxId,
        'type' => 1,
        'saft_type' => 1,
        'name' => "IVA {$value}",
        'value' => $value,
        'fiscal_zone' => 'PT',
        'active_by_default' => 0,
        ...$overrides,
    ];
}

it('resolves the tax id from the rate and company fiscal zone', function () {
    fakeTaxCatalog([
        vatRow(81, 23, ['fiscal_zone' => 'PT-MA']),
        vatRow(82, 23),
        vatRow(83, 6),
        vatRow(84, 23, ['type' => 2]),
        vatRow(85, 23, ['saft_type' => 3]),
    ]);

    expect(app(TaxResolver::class)->taxIdFor(23))->toBe(82)
        ->and(app(TaxResolver::class)->taxIdFor(6.0))->toBe(83);
});

it('uses the configured fiscal zone', function () {
    useMoloniSettings(['fiscal_zone' => 'PT-MA']);
    fakeTaxCatalog([vatRow(81, 22, ['fiscal_zone' => 'PT-MA']), vatRow(82, 23)]);

    expect(app(TaxResolver::class)->taxIdFor(22))->toBe(81)
        ->and(catchMoloniException(fn () => app(TaxResolver::class)->taxIdFor(23))->type)->toBe(FailureType::Validation);
});

it('compares rates with a small tolerance', function () {
    fakeTaxCatalog([vatRow(81, 13.5), vatRow(82, 23)]);

    expect(app(TaxResolver::class)->taxIdFor(13.5004))->toBe(81)
        ->and(app(TaxResolver::class)->taxIdFor(22.9999))->toBe(82);
});

it('prefers the tax active by default when two share a rate', function () {
    fakeTaxCatalog([vatRow(81, 23), vatRow(82, 23, ['active_by_default' => '1'])]);

    expect(app(TaxResolver::class)->taxIdFor(23))->toBe(82);
});

it('uses the override from settings when two taxes share a rate', function () {
    useMoloniSettings(['tax_overrides' => ['23' => 82, '6' => 83]]);
    fakeTaxCatalog([vatRow(81, 23), vatRow(82, 23), vatRow(83, 6)]);

    expect(app(TaxResolver::class)->taxIdFor(23))->toBe(82)
        ->and(app(TaxResolver::class)->taxIdFor(6))->toBe(83);
});

it('throws Validation when the rate is ambiguous and there is no override', function () {
    fakeTaxCatalog([vatRow(81, 23), vatRow(82, 23)]);

    $e = catchMoloniException(fn () => app(TaxResolver::class)->taxIdFor(23));

    expect($e->type)->toBe(FailureType::Validation)
        ->and($e->getMessage())->toContain('23%')
        ->and($e->getMessage())->toContain('settings');
});

it('throws Validation when two taxes active by default share a rate', function () {
    fakeTaxCatalog([vatRow(81, 23, ['active_by_default' => 1]), vatRow(82, 23, ['active_by_default' => 1])]);

    expect(catchMoloniException(fn () => app(TaxResolver::class)->taxIdFor(23))->type)->toBe(FailureType::Validation);
});

it('throws Validation when no tax has the rate', function () {
    fakeTaxCatalog([vatRow(82, 23)]);

    $e = catchMoloniException(fn () => app(TaxResolver::class)->taxIdFor(13));

    expect($e->type)->toBe(FailureType::Validation)
        ->and($e->getMessage())->toContain('13%')
        ->and($e->getMessage())->toContain('PT');
});

it('throws Validation when the override is not a tax in Moloni', function () {
    useMoloniSettings(['tax_overrides' => ['23' => 99]]);
    fakeTaxCatalog([vatRow(82, 23)]);

    $e = catchMoloniException(fn () => app(TaxResolver::class)->taxIdFor(23));

    expect($e->type)->toBe(FailureType::Validation)
        ->and($e->getMessage())->toContain('99');
});

it('throws Validation when the override is a tax with another rate', function () {
    useMoloniSettings(['tax_overrides' => ['23' => 83]]);
    fakeTaxCatalog([vatRow(82, 23), vatRow(83, 6)]);

    expect(catchMoloniException(fn () => app(TaxResolver::class)->taxIdFor(23))->type)->toBe(FailureType::Validation);
});

it('refuses to resolve a tax for a zero rate', function () {
    fakeTaxCatalog([vatRow(82, 0)]);

    expect(catchMoloniException(fn () => app(TaxResolver::class)->taxIdFor(0))->type)->toBe(FailureType::Validation);
});

it('accepts a zero-rate line whose exemption reason is a Moloni AT code', function (string $code) {
    fakeTaxCatalog([]);

    app(TaxResolver::class)->assertExemption(new InvoiceLine('Export', 1, '10', 0, exemptionReason: $code));

    expect(true)->toBeTrue();
})->with(['M07', 'M99', 'M40']);

it('rejects a zero-rate line without a valid exemption reason', function (?string $code) {
    fakeTaxCatalog([]);

    $e = catchMoloniException(fn () => app(TaxResolver::class)->assertExemption(new InvoiceLine('Export', 1, '10', 0, exemptionReason: $code)));

    expect($e->type)->toBe(FailureType::Validation);
})->with([
    'missing' => [null],
    'empty' => [''],
    'unknown' => ['MXX'],
    'Moloni code instead of the AT code' => ['M99X'],
]);

it('rejects an exemption reason on a taxed line', function () {
    fakeTaxCatalog([]);

    $e = catchMoloniException(fn () => app(TaxResolver::class)->assertExemption(new InvoiceLine('Fee', 1, '10', 23, exemptionReason: 'M07')));

    expect($e->type)->toBe(FailureType::Validation);
    Http::assertNothingSent();
});

it('accepts a taxed line without an exemption reason without calling Moloni', function () {
    fakeTaxCatalog([]);

    app(TaxResolver::class)->assertExemption(new InvoiceLine('Fee', 1, '10', 23));

    Http::assertNothingSent();
});
