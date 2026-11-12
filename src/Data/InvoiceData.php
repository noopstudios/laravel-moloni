<?php

namespace Noop\Moloni\Data;

use InvalidArgumentException;

/**
 * What to bill: the customer and the lines, as the application knows them. Frozen in the
 * document's row when it is requested; the document is issued from that snapshot.
 */
final readonly class InvoiceData
{
    /**
     * @param  list<InvoiceLine>  $lines
     * @param  string  $currency  ISO 4217 code.
     * @param  string|null  $yourReference  The customer's own reference, e.g. a purchase order.
     */
    public function __construct(
        public CustomerData $customer,
        public array $lines,
        public string $currency = 'EUR',
        public ?string $yourReference = null,
        public ?string $notes = null,
    ) {
        if ($lines === []) {
            throw new InvalidArgumentException('A document needs at least one line.');
        }

        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new InvalidArgumentException("The currency \"{$currency}\" must be three uppercase letters.");
        }
    }

    /**
     * Sum of the line bases, each rounded as Moloni rounds it.
     */
    public function netCents(): int
    {
        return array_sum(array_map(fn (InvoiceLine $line) => $line->lineNetCents(), $this->lines));
    }

    /**
     * Sum of the line taxes, each computed on its unrounded base and rounded, as Moloni does.
     */
    public function taxCents(): int
    {
        return array_sum(array_map(fn (InvoiceLine $line) => $line->lineTaxCents(), $this->lines));
    }

    /**
     * The document total Moloni will compute.
     */
    public function totalCents(): int
    {
        return $this->netCents() + $this->taxCents();
    }

    /**
     * @return array{customer: array<string, mixed>, lines: list<array<string, mixed>>, currency: string, yourReference: ?string, notes: ?string}
     */
    public function toArray(): array
    {
        return [
            'customer' => $this->customer->toArray(),
            'lines' => array_map(fn (InvoiceLine $line) => $line->toArray(), $this->lines),
            'currency' => $this->currency,
            'yourReference' => $this->yourReference,
            'notes' => $this->notes,
        ];
    }

    /**
     * @param  array<string, mixed>  $data  As returned by toArray().
     */
    public static function fromArray(array $data): self
    {
        /** @var array<string, mixed> $customer */
        $customer = $data['customer'];
        /** @var list<array<string, mixed>> $lines */
        $lines = $data['lines'];

        return new self(
            customer: CustomerData::fromArray($customer),
            lines: array_map(fn (array $line) => InvoiceLine::fromArray($line), $lines),
            currency: (string) ($data['currency'] ?? 'EUR'),
            yourReference: isset($data['yourReference']) ? (string) $data['yourReference'] : null,
            notes: isset($data['notes']) ? (string) $data['notes'] : null,
        );
    }
}
