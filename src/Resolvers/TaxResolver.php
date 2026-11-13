<?php

namespace Noop\Moloni\Resolvers;

use Noop\Moloni\Catalog\MoloniCatalog;
use Noop\Moloni\Data\InvoiceLine;
use Noop\Moloni\Enums\FailureType;
use Noop\Moloni\Exceptions\MoloniException;
use Noop\Moloni\Settings\MoloniSettings;
use Noop\Moloni\Support\Message;

/**
 * Turns a VAT rate into the Moloni tax to use, and checks the exemption reason of zero-rate lines.
 */
class TaxResolver
{
    public function __construct(
        private readonly MoloniCatalog $catalog,
        private readonly MoloniSettings $settings,
    ) {}

    /**
     * The tax configured for the rate in the settings; otherwise the only VAT tax of the company's
     * fiscal zone with that rate, preferring the one active by default when there are several.
     *
     * Zero-rate lines carry no tax: they are sent with an exemption reason (see assertExemption()).
     */
    public function taxIdFor(float $rate): int
    {
        return $this->tax($rate)['id'];
    }

    /**
     * The rate as Moloni holds it for the tax taxIdFor() resolves (e.g. 13.4996 → 13.5), or 0 for a
     * zero rate. Send and total with this rate, so the figures agree with Moloni's.
     */
    public function rateFor(float $rate): float
    {
        return InvoiceLine::isZeroRate($rate) ? 0.0 : $this->tax($rate)['rate'];
    }

    /**
     * @return array{id: int, rate: float}
     */
    private function tax(float $rate): array
    {
        if (InvoiceLine::isZeroRate($rate)) {
            throw new MoloniException(Message::get('A zero-rate line has no tax; it is sent with an exemption reason instead.'), FailureType::Validation);
        }

        $taxes = $this->catalog->taxes();
        $override = $this->override($rate);

        if ($override !== null) {
            // Moloni silently drops an unknown tax_id and issues the line without tax, so it must exist here.
            $row = $taxes->first(fn (array $row) => $this->id($row, 'tax_id') === $override && $this->sameRate($row['value'] ?? null, $rate));

            if ($row === null) {
                throw new MoloniException(Message::get('The tax :tax configured for :rate is not a :rate tax in Moloni.', ['tax' => $override, 'rate' => $this->percent($rate)]), FailureType::Validation);
            }

            return ['id' => $override, 'rate' => $this->catalogRate($row, $rate)];
        }

        $zone = $this->settings->fiscal_zone;
        $candidates = $taxes->filter(fn (array $row) => $this->id($row, 'saft_type') === 1
            && $this->id($row, 'type') === 1
            && ($row['fiscal_zone'] ?? null) === $zone
            && $this->sameRate($row['value'] ?? null, $rate));

        if ($candidates->isEmpty()) {
            throw new MoloniException(Message::get('Moloni has no :rate VAT tax in the fiscal zone :zone.', ['rate' => $this->percent($rate), 'zone' => $zone]), FailureType::Validation);
        }

        // Accounts can hold taxes identical in every field but the name; only active_by_default may tell them apart.
        $chosen = $candidates->count() > 1
            ? $candidates->filter(fn (array $row) => $this->id($row, 'active_by_default') === 1)
            : $candidates;

        if ($chosen->count() !== 1) {
            throw new MoloniException(Message::get('Moloni has more than one :rate VAT tax in the fiscal zone :zone; choose one in the settings.', ['rate' => $this->percent($rate), 'zone' => $zone]), FailureType::Validation);
        }

        $row = $chosen->first();
        $taxId = $this->id($row, 'tax_id');

        if ($taxId === null) {
            throw new MoloniException(Message::get('Moloni returned the :rate VAT tax of the fiscal zone :zone without a usable id.', ['rate' => $this->percent($rate), 'zone' => $zone]), FailureType::Validation);
        }

        return ['id' => $taxId, 'rate' => $this->catalogRate($row, $rate)];
    }

    /**
     * A zero-rate line needs an exemption reason that is an AT code known to Moloni (Moloni itself
     * accepts any text); a taxed line must not carry one.
     */
    public function assertExemption(InvoiceLine $line): void
    {
        // An empty reason is no reason, on taxed and zero-rate lines alike.
        $code = $line->exemptionReason === '' ? null : $line->exemptionReason;

        if (! $line->isZeroRated()) {
            if ($code !== null) {
                throw new MoloniException(Message::get('The line ":line" is taxed at :rate and cannot carry an exemption reason.', ['line' => $line->name, 'rate' => $this->percent($line->taxRate)]), FailureType::Validation);
            }

            return;
        }

        if ($code === null) {
            throw new MoloniException(Message::get('The zero-rate line ":line" needs an exemption reason.', ['line' => $line->name]), FailureType::Validation);
        }

        if (! $this->catalog->hasExemption($code)) {
            throw new MoloniException(Message::get('The exemption reason :code does not exist in Moloni.', ['code' => $code]), FailureType::Validation);
        }
    }

    private function override(float $rate): ?int
    {
        // Keys are the rate as a string ("23", "13.5"); PHP turns whole-number keys into ints.
        foreach ($this->settings->tax_overrides as $percentage => $taxId) {
            if ($this->sameRate($percentage, $rate)) {
                return $taxId;
            }
        }

        return null;
    }

    /**
     * The rate of a tax already matched with sameRate(), so its value is numeric.
     *
     * @param  array<string, mixed>|null  $row
     */
    private function catalogRate(?array $row, float $rate): float
    {
        $value = $row['value'] ?? null;

        return is_numeric($value) ? (float) $value : $rate;
    }

    private function sameRate(mixed $value, float $rate): bool
    {
        return is_numeric($value) && abs((float) $value - $rate) < InvoiceLine::RATE_TOLERANCE;
    }

    /**
     * @param  array<string, mixed>|null  $row
     */
    private function id(?array $row, string $field): ?int
    {
        $value = $row[$field] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    private function percent(float $rate): string
    {
        return rtrim(rtrim(number_format($rate, 3, '.', ''), '0'), '.').'%';
    }
}
