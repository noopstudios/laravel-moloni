<?php

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Noop\Moloni\Data\CustomerData;
use Noop\Moloni\Enums\FailureType;
use Noop\Moloni\Tax\DefaultTaxRule;
use Noop\Moloni\Tax\TaxDecision;
use Noop\Moloni\Tax\TaxRule;
use Noop\Moloni\Tax\ViesResult;

beforeEach(function () {
    Http::preventStrayRequests();
    config(['moloni.vies.enabled' => true, 'moloni.vies.base_url' => 'https://vies.example.test/rest-api']);
});

function taxCustomer(?string $country, bool $isBusiness = true, ?string $vat = '123456789'): CustomerData
{
    return new CustomerData('U1', $vat, 'Acme', null, null, null, null, $country, $isBusiness);
}

function fakeVies(bool $valid): void
{
    Http::fake(['*' => ['valid' => $valid, 'requestIdentifier' => 'REQ-1', 'userError' => $valid ? 'VALID' : 'INVALID']]);
}

it('charges the standard rate to a Portuguese customer without calling VIES', function () {
    Http::fake();

    $decision = app(TaxRule::class)->decide(taxCustomer('PT'), 23.0);

    expect($decision->rate)->toBe(23.0)->and($decision->exemptionReason)->toBeNull()->and($decision->vies)->toBeNull();
    Http::assertNothingSent();
});

it('treats an unknown country as Portugal', function () {
    Http::fake();

    $decision = app(TaxRule::class)->decide(taxCustomer(null), 23.0);

    expect($decision->rate)->toBe(23.0)->and($decision->exemptionReason)->toBeNull();
    Http::assertNothingSent();
});

it('exempts an EU business with a valid VAT number', function () {
    fakeVies(true);

    $decision = app(TaxRule::class)->decide(taxCustomer('de'), 23.0);

    expect($decision->rate)->toBe(0.0)
        ->and($decision->exemptionReason)->toBe('M40')
        ->and($decision->vies?->valid)->toBeTrue()
        ->and($decision->vies?->requestIdentifier)->toBe('REQ-1');
});

it('charges the standard rate to an EU business with an invalid VAT number', function () {
    fakeVies(false);

    $decision = app(TaxRule::class)->decide(taxCustomer('DE'), 23.0);

    expect($decision->rate)->toBe(23.0)->and($decision->exemptionReason)->toBeNull()->and($decision->vies?->valid)->toBeFalse();
});

it('charges the standard rate when VIES is unavailable and the setting is charge', function () {
    useMoloniSettings(['vies_unavailable' => 'charge']);
    Http::fake(['*' => ['valid' => false, 'userError' => 'MS_UNAVAILABLE']]);

    $decision = app(TaxRule::class)->decide(taxCustomer('DE'), 23.0);

    expect($decision->rate)->toBe(23.0)->and($decision->vies?->available)->toBeFalse();
});

it('blocks when VIES is unavailable and the setting is block', function () {
    useMoloniSettings(['vies_unavailable' => 'block']);
    Http::fake(['*' => Http::response('down', 503)]);

    $e = catchMoloniException(fn () => app(TaxRule::class)->decide(taxCustomer('DE'), 23.0));

    expect($e->type)->toBe(FailureType::Validation);
});

it('charges the standard rate to an EU consumer without calling VIES', function () {
    Http::fake();

    $decision = app(TaxRule::class)->decide(taxCustomer('DE', isBusiness: false), 23.0);

    expect($decision->rate)->toBe(23.0)->and($decision->exemptionReason)->toBeNull()->and($decision->vies)->toBeNull();
    Http::assertNothingSent();
});

it('treats an EU business without a VAT number as a consumer', function () {
    Http::fake();

    $decision = app(TaxRule::class)->decide(taxCustomer('DE', vat: ' '), 23.0);

    expect($decision->rate)->toBe(23.0);
    Http::assertNothingSent();
});

it('treats an EU business as invalid when VIES is disabled', function () {
    config(['moloni.vies.enabled' => false]);
    Http::fake();

    $decision = app(TaxRule::class)->decide(taxCustomer('DE'), 23.0);

    expect($decision->rate)->toBe(23.0)->and($decision->vies)->toBeNull();
    Http::assertNothingSent();
});

it('exempts a business outside the EU without calling VIES', function () {
    Http::fake();

    $decision = app(TaxRule::class)->decide(taxCustomer('US'), 23.0);

    expect($decision->rate)->toBe(0.0)->and($decision->exemptionReason)->toBe('M40')->and($decision->vies)->toBeNull();
    Http::assertNothingSent();
});

it('exempts a consumer outside the EU with its own code', function () {
    useMoloniSettings(['exemption_reasons' => ['eu_business' => 'M40', 'non_eu_business' => 'M40', 'non_eu_consumer' => 'M44']]);
    Http::fake();

    $decision = app(TaxRule::class)->decide(taxCustomer('BR', isBusiness: false), 23.0);

    expect($decision->rate)->toBe(0.0)->and($decision->exemptionReason)->toBe('M44');
    Http::assertNothingSent();
});

it('treats Northern Ireland as outside the EU', function () {
    Http::fake();

    expect(app(TaxRule::class)->decide(taxCustomer('XI'), 23.0)->rate)->toBe(0.0);
});

it('sends EL for Greece', function () {
    fakeVies(true);

    app(TaxRule::class)->decide(taxCustomer('GR'), 23.0);

    Http::assertSent(fn (Request $request) => $request['countryCode'] === 'EL');
});

it('fails with Validation when the exemption reason for the scenario is not configured', function (string $country, bool $isBusiness, string $missing) {
    $reasons = ['eu_business' => 'M40', 'non_eu_business' => 'M40', 'non_eu_consumer' => 'M44'];
    $reasons[$missing] = '';
    useMoloniSettings(['exemption_reasons' => $reasons]);
    fakeVies(true);

    $e = catchMoloniException(fn () => app(TaxRule::class)->decide(taxCustomer($country, $isBusiness), 23.0));

    expect($e->type)->toBe(FailureType::Validation);
})->with([
    'eu business' => ['DE', true, 'eu_business'],
    'non-eu business' => ['US', true, 'non_eu_business'],
    'non-eu consumer' => ['US', false, 'non_eu_consumer'],
]);

it('lets the project bind its own TaxRule', function () {
    $own = new class implements TaxRule
    {
        public function decide(CustomerData $customer, float $standardRate): TaxDecision
        {
            return new TaxDecision(6.0, null);
        }
    };

    app()->instance(TaxRule::class, $own);
    expect(app(TaxRule::class)->decide(taxCustomer('PT'), 23.0)->rate)->toBe(6.0);
});

it('lets the project name its own TaxRule in config', function () {
    config(['moloni.tax_rule' => get_class(new class implements TaxRule
    {
        public function decide(CustomerData $customer, float $standardRate): TaxDecision
        {
            return new TaxDecision(13.0, null, new ViesResult(false, null, null, CarbonImmutable::now()));
        }
    })]);

    expect(app(TaxRule::class)->decide(taxCustomer('PT'), 23.0)->rate)->toBe(13.0);
});

it('binds the default rule by default', function () {
    expect(app(TaxRule::class))->toBeInstanceOf(DefaultTaxRule::class);
});
