<?php

namespace Noop\Moloni\Data;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The billing customer of a document, as the application knows it.
 */
final readonly class CustomerData
{
    /** ISO-8601 with microseconds and offset, so a date survives the round trip unchanged. */
    public const DATE_FORMAT = 'Y-m-d\\TH:i:s.uP';

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

    /**
     * @return array{key: string, vat: ?string, name: ?string, email: ?string, address: ?string, postalCode: ?string, city: ?string, country: ?string, isBusiness: bool, createdAt: ?string}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'vat' => $this->vat,
            'name' => $this->name,
            'email' => $this->email,
            'address' => $this->address,
            'postalCode' => $this->postalCode,
            'city' => $this->city,
            'country' => $this->country,
            'isBusiness' => $this->isBusiness,
            'createdAt' => $this->createdAt?->format(self::DATE_FORMAT),
        ];
    }

    /**
     * @param  array<string, mixed>  $data  As returned by toArray().
     */
    public static function fromArray(array $data): self
    {
        return new self(
            key: (string) $data['key'],
            vat: self::nullableString($data['vat'] ?? null),
            name: self::nullableString($data['name'] ?? null),
            email: self::nullableString($data['email'] ?? null),
            address: self::nullableString($data['address'] ?? null),
            postalCode: self::nullableString($data['postalCode'] ?? null),
            city: self::nullableString($data['city'] ?? null),
            country: self::nullableString($data['country'] ?? null),
            isBusiness: (bool) ($data['isBusiness'] ?? false),
            createdAt: isset($data['createdAt']) ? CarbonImmutable::parse((string) $data['createdAt']) : null,
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
