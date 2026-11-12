<?php

namespace Noop\Moloni\Data;

use InvalidArgumentException;
use Noop\Moloni\Support\Payload;

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
     * @param  array<array-key, mixed>  $data  As returned by toArray().
     */
    public static function fromArray(array $data): self
    {
        $lines = [];
        foreach (Payload::requiredArray($data, 'lines', 'invoice data') as $line) {
            if (! is_array($line)) {
                throw new InvalidArgumentException('The frozen invoice data has an invalid line.');
            }
            $lines[] = InvoiceLine::fromArray($line);
        }

        return new self(
            customer: CustomerData::fromArray(Payload::requiredArray($data, 'customer', 'invoice data')),
            lines: $lines,
            currency: (string) Payload::required($data, 'currency', 'invoice data'),
            yourReference: isset($data['yourReference']) ? (string) $data['yourReference'] : null,
            notes: isset($data['notes']) ? (string) $data['notes'] : null,
        );
    }
}
