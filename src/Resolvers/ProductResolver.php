<?php

namespace Noop\Moloni\Resolvers;

use Noop\Moloni\Data\InvoiceLine;
use Noop\Moloni\Enums\FailureType;
use Noop\Moloni\Exceptions\MoloniException;
use Noop\Moloni\Http\MoloniConnector;
use Noop\Moloni\Settings\MoloniSettings;

/**
 * Turns an invoice line into the Moloni product to issue it with, creating a service product
 * for a reference Moloni does not know yet.
 *
 * Bound per scope: references resolved here are remembered for the request or job only.
 */
class ProductResolver
{
    /** @var array<string, int> Normalised reference => product_id. */
    private array $resolved = [];

    public function __construct(
        private readonly MoloniConnector $connector,
        private readonly MoloniSettings $settings,
        private readonly TaxResolver $taxes,
    ) {}

    public function resolve(InvoiceLine $line): int
    {
        if ($line->productId !== null) {
            return $line->productId;
        }

        // Moloni holds references unique regardless of case and surrounding spaces.
        $reference = trim((string) $line->reference);

        if ($reference === '') {
            return $this->settings->default_product_id
                ?? throw new MoloniException("The line \"{$line->name}\" has no product or reference, and no default product is configured.", FailureType::Validation);
        }

        return $this->resolved[mb_strtolower($reference)] ??= $this->find($reference) ?? $this->create($line, $reference);
    }

    /**
     * getByReference with exact: 1 returns a list, so the match is checked here, ignoring case and
     * surrounding spaces as Moloni does; among duplicates the oldest product wins.
     */
    private function find(string $reference): ?int
    {
        $rows = $this->connector->read('products/getByReference', ['reference' => $reference, 'exact' => 1]);

        $ids = collect($rows)
            ->filter(fn (mixed $row) => is_array($row)
                && is_string($row['reference'] ?? null)
                && mb_strtolower(trim($row['reference'])) === mb_strtolower($reference)
                && is_numeric($row['product_id'] ?? null))
            ->map(fn (array $row) => (int) $row['product_id']);

        return $ids->isEmpty() ? null : $ids->min();
    }

    private function create(InvoiceLine $line, string $reference): int
    {
        $categoryId = $this->settings->default_category_id
            ?? throw new MoloniException("No default product category is configured, so the product {$reference} cannot be created.", FailureType::Validation);
        $unitId = $this->settings->default_unit_id
            ?? throw new MoloniException("No default measurement unit is configured, so the product {$reference} cannot be created.", FailureType::Validation);

        $this->taxes->assertExemption($line);
        $tax = $line->isZeroRated()
            ? ['exemption_reason' => $line->exemptionReason]
            : ['taxes' => [[
                'tax_id' => $this->taxes->taxIdFor($line->taxRate),
                'value' => $this->taxes->rateFor($line->taxRate),
                'order' => 1,
                'cumulative' => 0,
            ]]];

        try {
            $result = $this->connector->write('products/insert', [
                'category_id' => $categoryId,
                'type' => 2,
                'name' => $line->name,
                'reference' => $reference,
                'price' => (float) $line->unitNetPrice,
                'unit_id' => $unitId,
                'has_stock' => 0,
                ...$tax,
            ]);
        } catch (MoloniException $e) {
            if ($e->type !== FailureType::Unknown) {
                throw $e;
            }

            // The product may have been created: look once, never insert again blindly.
            return $this->findAfterLostInsert($reference) ?? throw $e;
        }

        if (! is_numeric($result['product_id'] ?? null)) {
            // Created, but the id is missing: look it up once.
            return $this->findAfterLostInsert($reference)
                ?? throw new MoloniException("Moloni confirmed the product {$reference} without returning its id.", FailureType::Unknown);
        }

        return (int) $result['product_id'];
    }

    private function findAfterLostInsert(string $reference): ?int
    {
        try {
            return $this->find($reference);
        } catch (MoloniException) {
            return null;
        }
    }
}
