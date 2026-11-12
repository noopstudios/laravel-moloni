<?php

namespace Noop\Moloni\Resolvers;

use Noop\Moloni\Catalog\MoloniCatalog;
use Noop\Moloni\Data\InvoiceLine;
use Noop\Moloni\Enums\FailureType;
use Noop\Moloni\Exceptions\MoloniException;
use Noop\Moloni\Settings\MoloniSettings;

/**
 * Turns a VAT rate into the Moloni tax to use, and checks the exemption reason of zero-rate lines.
 */
class TaxResolver
{
    private const RATE_TOLERANCE = 0.001;

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
        if ($rate <= 0) {
            throw new MoloniException('A zero-rate line has no tax; it is sent with an exemption reason instead.', FailureType::Validation);
        }

        $taxes = $this->catalog->taxes();
        $override = $this->override($rate);

        if ($override !== null) {
            // Moloni silently drops an unknown tax_id and issues the line without tax, so it must exist here.
            $known = $taxes->contains(fn (array $row) => $this->id($row, 'tax_id') === $override && $this->sameRate($row['value'] ?? null, $rate));

            if (! $known) {
                throw new MoloniException("The tax {$override} configured for {$this->percent($rate)} is not a {$this->percent($rate)} tax in Moloni.", FailureType::Validation);
            }

            return $override;
        }

        $zone = $this->settings->fiscal_zone;
        $candidates = $taxes->filter(fn (array $row) => $this->id($row, 'saft_type') === 1
            && $this->id($row, 'type') === 1
            && ($row['fiscal_zone'] ?? null) === $zone
            && $this->sameRate($row['value'] ?? null, $rate));

        if ($candidates->isEmpty()) {
            throw new MoloniException("Moloni has no {$this->percent($rate)} VAT tax in the fiscal zone {$zone}.", FailureType::Validation);
        }

        // Accounts can hold taxes identical in every field but the name; only active_by_default may tell them apart.
        $chosen = $candidates->count() > 1
            ? $candidates->filter(fn (array $row) => $this->id($row, 'active_by_default') === 1)
            : $candidates;
        $taxId = $chosen->count() === 1 ? $this->id($chosen->first(), 'tax_id') : null;

        if ($taxId === null) {
            throw new MoloniException("Moloni has more than one {$this->percent($rate)} VAT tax in the fiscal zone {$zone}; choose one in the settings.", FailureType::Validation);
        }

        return $taxId;
    }

    /**
     * A zero-rate line needs an exemption reason that is an AT code known to Moloni (Moloni itself
     * accepts any text); a taxed line must not carry one.
     */
    public function assertExemption(InvoiceLine $line): void
    {
        $code = $line->exemptionReason;

        if ($line->taxRate > 0) {
            if ($code !== null) {
                throw new MoloniException("The line \"{$line->name}\" is taxed at {$this->percent($line->taxRate)} and cannot carry an exemption reason.", FailureType::Validation);
            }

            return;
        }

        if ($code === null || $code === '') {
            throw new MoloniException("The zero-rate line \"{$line->name}\" needs an exemption reason.", FailureType::Validation);
        }

        // Matched on the AT code, which is what lines carry; Moloni's own code differs from it for some entries.
        $known = $this->catalog->taxExemptions()->contains(fn (array $row) => (string) ($row['at_code'] ?? $row['code'] ?? '') === $code);

        if (! $known) {
            throw new MoloniException("The exemption reason {$code} does not exist in Moloni.", FailureType::Validation);
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

    private function sameRate(mixed $value, float $rate): bool
    {
        return is_numeric($value) && abs((float) $value - $rate) < self::RATE_TOLERANCE;
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
