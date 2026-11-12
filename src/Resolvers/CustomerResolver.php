<?php

namespace Noop\Moloni\Resolvers;

use Noop\Moloni\Catalog\MoloniCatalog;
use Noop\Moloni\Data\CustomerData;
use Noop\Moloni\Data\ResolvedCustomer;
use Noop\Moloni\Enums\FailureType;
use Noop\Moloni\Exceptions\MoloniException;
use Noop\Moloni\Http\MoloniConnector;
use Noop\Moloni\Settings\MoloniSettings;
use Noop\Moloni\Support\Vat;

/**
 * Finds or creates the Moloni customer of a document: by VAT number, else by the customer number
 * built from the application's key, keeping the name and address up to date. Documents without
 * any billing data go to Moloni's generic final-consumer customer.
 */
class CustomerResolver
{
    /** Number of Moloni's generic final-consumer customer, which every company has by default. */
    private const GENERIC_NUMBER = '9999';

    private const MAX_NUMBER_LENGTH = 20;

    private const UNKNOWN = 'Desconhecido';

    private const NO_PORTUGUESE_POSTAL_CODE = '0000-000';

    public function __construct(
        private readonly MoloniConnector $connector,
        private readonly MoloniCatalog $catalog,
        private readonly MoloniSettings $settings,
    ) {}

    public function resolve(CustomerData $customer): ResolvedCustomer
    {
        $finalConsumer = Vat::isFinalConsumer($customer->vat);

        if ($finalConsumer && $this->text($customer->name) === null && $this->text($customer->address) === null && $this->text($customer->email) === null) {
            return $this->generic();
        }

        $number = $this->numberFor($customer);
        // Many customers share the final-consumer VAT, so those are found by number only.
        $vat = $finalConsumer ? null : Vat::normalize($customer->vat);
        $row = $this->find($vat, $number);

        if ($row === null) {
            return $this->create($customer, $vat, $number);
        }

        $this->update($row, $customer);

        return $this->resolved($row);
    }

    /**
     * {prefix}{yy}-{key}, with F at the end for a final consumer. Longer numbers lose the end of
     * the key: Moloni allows 20 characters.
     */
    public function numberFor(CustomerData $customer): string
    {
        $prefix = config('moloni.prefix');

        if (! is_string($prefix) || trim($prefix) === '') {
            throw new MoloniException('No customer number prefix is configured (moloni.prefix).', FailureType::Validation);
        }

        if (trim($customer->key) === '') {
            throw new MoloniException('The customer has no key to build its Moloni number from.', FailureType::Validation);
        }

        $head = trim($prefix).($customer->createdAt ?? now())->format('y').'-';
        $suffix = Vat::isFinalConsumer($customer->vat) ? 'F' : '';
        $room = self::MAX_NUMBER_LENGTH - mb_strlen($head) - mb_strlen($suffix);

        if ($room < 1) {
            throw new MoloniException("The prefix {$prefix} leaves no room for the customer key in a Moloni customer number.", FailureType::Validation);
        }

        return $head.mb_substr(trim($customer->key), 0, $room).$suffix;
    }

    private function generic(): ResolvedCustomer
    {
        $row = $this->pick($this->search('customers/getByNumber', ['number' => self::GENERIC_NUMBER], 'number', self::GENERIC_NUMBER), self::GENERIC_NUMBER)
            ?? throw new MoloniException('Moloni has no customer numbered '.self::GENERIC_NUMBER.'. Create or keep Moloni\'s generic final-consumer customer with that number to issue documents without billing data.', FailureType::Validation);

        return $this->resolved($row);
    }

    /**
     * getByVat and getByNumber are substring matches, so equality is checked here.
     *
     * @return array<string, mixed>|null
     */
    private function find(?string $vat, string $number): ?array
    {
        if ($vat !== null) {
            $row = $this->pick($this->search('customers/getByVat', ['vat' => $vat], 'vat', $vat), $number);

            if ($row !== null) {
                return $row;
            }
        }

        $row = $this->pick($this->search('customers/getByNumber', ['number' => $number], 'number', $number), $number);

        if ($row === null) {
            return null;
        }

        // Moloni ignores a VAT change once the customer has documents, so the document would carry the wrong VAT.
        $rowVat = is_scalar($row['vat'] ?? null) ? (string) $row['vat'] : null;
        $sameVat = $vat === null ? Vat::isFinalConsumer($rowVat) : Vat::normalize($rowVat) === $vat;

        if (! $sameVat) {
            $expected = $vat ?? Vat::FINAL_CONSUMER;

            throw new MoloniException("The Moloni customer {$number} has the VAT number {$rowVat}, not {$expected}. Fix the customer in Moloni.", FailureType::Validation);
        }

        return $row;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return list<array<string, mixed>>
     */
    private function search(string $endpoint, array $params, string $field, string $value): array
    {
        $matches = [];

        foreach ($this->connector->readAll($endpoint, $params) as $row) {
            if (! is_array($row) || ! is_numeric($row['customer_id'] ?? null) || ! is_scalar($row[$field] ?? null)) {
                continue;
            }

            $found = (string) $row[$field];

            if ($field === 'vat' ? Vat::normalize($found) === $value : $found === $value) {
                /** @var array<string, mixed> $row */
                $matches[] = $row;
            }
        }

        return $matches;
    }

    /**
     * The match with our number, else the oldest customer.
     *
     * @param  list<array<string, mixed>>  $matches
     * @return array<string, mixed>|null
     */
    private function pick(array $matches, string $number): ?array
    {
        foreach ($matches as $row) {
            if ((string) $row['number'] === $number) {
                return $row;
            }
        }

        usort($matches, fn (array $a, array $b) => (int) $a['customer_id'] <=> (int) $b['customer_id']);

        return $matches[0] ?? null;
    }

    /**
     * Sends only the fields that changed. An empty value never blanks what Moloni has, and the VAT
     * and number are never sent: Moloni silently ignores a VAT change once documents exist.
     *
     * @param  array<string, mixed>  $row
     */
    private function update(array $row, CustomerData $customer): void
    {
        $wanted = [
            'name' => $this->text($customer->name),
            'address' => $this->text($customer->address),
            'city' => $this->text($customer->city),
            'zip_code' => $this->postalCode($customer),
            'country_id' => $this->catalog->countryId($customer->country),
            'email' => $this->text($customer->email),
        ];
        $changes = [];

        foreach ($wanted as $field => $value) {
            $current = is_scalar($row[$field] ?? null) ? trim((string) $row[$field]) : '';

            if ($value !== null && (string) $value !== $current) {
                $changes[$field] = $value;
            }
        }

        if ($changes !== []) {
            $this->connector->write('customers/update', ['customer_id' => (int) $row['customer_id'], ...$changes]);
        }
    }

    private function create(CustomerData $customer, ?string $vat, string $number): ResolvedCustomer
    {
        $maturityDateId = $this->settings->maturity_dates[0]
            ?? throw new MoloniException("No zero-day maturity date is configured, so the customer {$number} cannot be created.", FailureType::Validation);
        $paymentMethodId = array_values($this->settings->payment_methods)[0]
            ?? throw new MoloniException("No payment method is configured, so the customer {$number} cannot be created.", FailureType::Validation);

        $postalCode = $this->postalCode($customer) ?? ($this->isPortugal($customer) ? self::NO_PORTUGUESE_POSTAL_CODE : null);
        $email = $this->text($customer->email);

        try {
            $result = $this->connector->write('customers/insert', [
                'vat' => $vat ?? Vat::FINAL_CONSUMER,
                'number' => $number,
                'name' => $this->text($customer->name) ?? self::UNKNOWN,
                'language_id' => $this->catalog->languageIdFor($customer->country),
                'address' => $this->text($customer->address) ?? self::UNKNOWN,
                'city' => $this->text($customer->city) ?? self::UNKNOWN,
                ...($postalCode !== null ? ['zip_code' => $postalCode] : []),
                'country_id' => $this->catalog->countryId($customer->country),
                ...($email !== null ? ['email' => $email] : []),
                'maturity_date_id' => $maturityDateId,
                'payment_method_id' => $paymentMethodId,
                // Optional in the docs, but Moloni requires them to be present.
                'salesman_id' => 0,
                'payment_day' => 0,
                'discount' => 0,
                'credit_limit' => 0,
                'delivery_method_id' => 0,
            ]);
        } catch (MoloniException $e) {
            if ($e->type !== FailureType::Unknown) {
                throw $e;
            }

            // The customer may have been created: look once, never insert again blindly.
            $row = $this->findAfterLostInsert($vat, $number) ?? throw $e;

            return $this->resolved($row);
        }

        if (! is_numeric($result['customer_id'] ?? null)) {
            throw new MoloniException("Moloni confirmed the customer {$number} without returning its id.", FailureType::Unknown);
        }

        return new ResolvedCustomer((int) $result['customer_id'], $number);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findAfterLostInsert(?string $vat, string $number): ?array
    {
        try {
            return $this->find($vat, $number);
        } catch (MoloniException) {
            return null;
        }
    }

    /**
     * A Portuguese postal code as NNNN-NNN, or null when it is empty or invalid; other countries'
     * codes are passed through.
     */
    private function postalCode(CustomerData $customer): ?string
    {
        $code = $this->text($customer->postalCode);

        if ($code === null || ! $this->isPortugal($customer)) {
            return $code;
        }

        return preg_match('/^(\d{4})\s*-?\s*(\d{3})$/', $code, $parts) === 1 ? "{$parts[1]}-{$parts[2]}" : null;
    }

    private function isPortugal(CustomerData $customer): bool
    {
        return strtoupper($customer->country) === 'PT';
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function resolved(array $row): ResolvedCustomer
    {
        return new ResolvedCustomer((int) $row['customer_id'], (string) $row['number']);
    }

    private function text(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
