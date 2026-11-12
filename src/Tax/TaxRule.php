<?php

namespace Noop\Moloni\Tax;

use Noop\Moloni\Data\CustomerData;
use Noop\Moloni\Exceptions\MoloniException;

/**
 * Decides which VAT applies to a customer. The application calls it before asking for payment and
 * builds the lines from the decision; the package never applies it when issuing.
 */
interface TaxRule
{
    /**
     * @param  float  $standardRate  The VAT percentage charged to a Portuguese customer.
     *
     * @throws MoloniException When the decision cannot be made (Validation).
     */
    public function decide(CustomerData $customer, float $standardRate): TaxDecision;
}
