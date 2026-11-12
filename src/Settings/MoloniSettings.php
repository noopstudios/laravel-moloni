<?php

namespace Noop\Moloni\Settings;

use Illuminate\Database\QueryException;
use Spatie\LaravelSettings\Exceptions\MissingSettings;
use Spatie\LaravelSettings\Settings;

class MoloniSettings extends Settings
{
    public ?int $company_id;

    /** @var array<string, int> Document type (proforma, invoice, invoice_receipt, receipt, credit_note) => document_set_id. */
    public array $document_sets;

    /** @var array<string, int> Free key => payment_method_id. */
    public array $payment_methods;

    /** @var array<int, int> Days => maturity_date_id. */
    public array $maturity_dates;

    public ?int $default_category_id;

    public ?int $default_unit_id;

    public ?int $default_product_id;

    /** @var array<int|string, int> VAT percentage => tax_id. */
    public array $tax_overrides;

    /** @var array<string, string> eu_business, non_eu_business, non_eu_consumer => exemption code. */
    public array $exemption_reasons;

    /** What to do when VIES cannot be reached: "charge" VAT or "block" the document. */
    public string $vies_unavailable;

    public static function group(): string
    {
        return 'moloni';
    }

    /**
     * The configured company, or null before the settings exist (fresh install, migrations not run).
     */
    public function companyIdOrNull(): ?int
    {
        try {
            // Loading goes through a method call; a plain property read hides the throw from static analysis.
            $companyId = $this->toArray()['company_id'] ?? null;
        } catch (MissingSettings|QueryException) {
            return null;
        }

        return is_int($companyId) ? $companyId : null;
    }
}
