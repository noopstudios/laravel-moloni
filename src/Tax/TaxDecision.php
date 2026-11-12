<?php

namespace Noop\Moloni\Tax;

/**
 * The VAT a customer is charged: the rate, the exemption code when the rate is 0, and the VIES
 * result when VIES was consulted.
 */
final readonly class TaxDecision
{
    public function __construct(
        public float $rate,
        public ?string $exemptionReason,
        public ?ViesResult $vies = null,
    ) {}
}
