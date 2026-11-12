<?php

namespace Noop\Moloni\Tax;

use Noop\Moloni\Data\CustomerData;
use Noop\Moloni\Enums\FailureType;
use Noop\Moloni\Exceptions\MoloniException;
use Noop\Moloni\Settings\MoloniSettings;

/**
 * Portugal pays VAT; an EU business with a VAT number VIES confirms is exempt (M40 by default);
 * an EU consumer pays VAT; outside the EU there is no VAT. Northern Ireland (XI) counts as outside.
 */
class DefaultTaxRule implements TaxRule
{
    /** ISO 3166-1 alpha-2 codes of the EU member states. */
    public const EU_COUNTRIES = [
        'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GR', 'HR', 'HU',
        'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK',
    ];

    public function __construct(
        private readonly MoloniSettings $settings,
        private readonly ViesClient $vies,
    ) {}

    public function decide(CustomerData $customer, float $standardRate): TaxDecision
    {
        $country = strtoupper(trim((string) $customer->country)) ?: 'PT';

        if ($country === 'PT') {
            return new TaxDecision($standardRate, null);
        }

        $hasVat = trim((string) $customer->vat) !== '';

        if (! in_array($country, self::EU_COUNTRIES, true)) {
            return $this->exempt($customer->isBusiness && $hasVat ? 'non_eu_business' : 'non_eu_consumer');
        }

        if (! $customer->isBusiness || ! $hasVat || ! config('moloni.vies.enabled')) {
            return new TaxDecision($standardRate, null);
        }

        $result = $this->vies->check($country, (string) $customer->vat);

        if (! $result->available) {
            if ($this->settings->vies_unavailable === 'block') {
                throw new MoloniException(__('The VAT number could not be validated right now. Please try again later.'), FailureType::Validation);
            }

            return new TaxDecision($standardRate, null, $result);
        }

        if ($result->valid !== true) {
            return new TaxDecision($standardRate, null, $result);
        }

        return $this->exempt('eu_business', $result);
    }

    private function exempt(string $scenario, ?ViesResult $vies = null): TaxDecision
    {
        $code = $this->settings->exemption_reasons[$scenario] ?? '';

        if (trim($code) === '') {
            throw new MoloniException(__('No exemption reason is configured for :scenario.', ['scenario' => $scenario]), FailureType::Validation);
        }

        return new TaxDecision(0.0, $code, $vies);
    }
}
