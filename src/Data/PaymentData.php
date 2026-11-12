<?php

namespace Noop\Moloni\Data;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * A payment received, for an invoice-receipt or a receipt.
 */
final readonly class PaymentData
{
    /**
     * @param  string  $method  A key of MoloniSettings::$payment_methods.
     */
    public function __construct(
        public string $method,
        public CarbonInterface $paidAt,
        public int $amountCents,
        public ?string $notes = null,
    ) {
        if (trim($method) === '') {
            throw new InvalidArgumentException('A payment needs a method.');
        }

        if ($amountCents <= 0) {
            throw new InvalidArgumentException('A payment amount must be greater than zero.');
        }
    }

    /**
     * @return array{method: string, paidAt: string, amountCents: int, notes: ?string}
     */
    public function toArray(): array
    {
        return [
            'method' => $this->method,
            'paidAt' => $this->paidAt->format(CustomerData::DATE_FORMAT),
            'amountCents' => $this->amountCents,
            'notes' => $this->notes,
        ];
    }

    /**
     * @param  array<string, mixed>  $data  As returned by toArray().
     */
    public static function fromArray(array $data): self
    {
        return new self(
            method: (string) $data['method'],
            paidAt: CarbonImmutable::parse((string) $data['paidAt']),
            amountCents: (int) $data['amountCents'],
            notes: isset($data['notes']) ? (string) $data['notes'] : null,
        );
    }
}
