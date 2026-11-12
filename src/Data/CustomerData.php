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
     * @param  string|null  $country  ISO 3166-1 alpha-2 code; null when unknown (new customers are then Portuguese).
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
        public ?string $country = null,
        public bool $isBusiness = false,
        public ?CarbonInterface $createdAt = null,
    ) {}
}
