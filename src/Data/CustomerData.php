<?php

namespace Noop\Moloni\Data;

use Carbon\CarbonInterface;

/**
 * The billing customer of a document, as the application knows it.
 */
final readonly class CustomerData
{
    /**
     * @param  string  $key  The application's stable id for the customer; part of the Moloni customer number.
     * @param  string  $country  ISO 3166-1 alpha-2 code.
     * @param  CarbonInterface|null  $createdAt  Its year goes into the customer number; the current year when null.
     */
    public function __construct(
        public string $key,
        public ?string $vat,
        public ?string $name,
        public ?string $email,
        public ?string $address,
        public ?string $postalCode,
        public ?string $city,
        public string $country = 'PT',
        public bool $isBusiness = false,
        public ?CarbonInterface $createdAt = null,
    ) {}
}
