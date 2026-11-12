<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Noop\Moloni\Data\CustomerData;
use Noop\Moloni\Data\InvoiceData;
use Noop\Moloni\Data\InvoiceLine;
use Noop\Moloni\Data\PaymentData;
use Noop\Moloni\Tax\ViesResult;

function idCustomer(): CustomerData
{
    return new CustomerData(
        key: 'U12',
        vat: '509999999',
        name: 'Acme Lda',
        email: 'billing@acme.test',
        address: 'Rua Um 1',
        postalCode: '1000-001',
        city: 'Lisboa',
        country: 'PT',
        isBusiness: true,
        createdAt: Carbon::parse('2025-07-04 10:11:12.345678', 'Europe/Lisbon'),
    );
}

/**
 * @return array<string, mixed>
 */
function idValidData(): array
{
    return (new InvoiceData(idCustomer(), [new InvoiceLine('X', 1, '1', 23)]))->toArray();
}

it('computes the total in cents from lines with five-decimal net prices', function () {
    expect((new InvoiceData(idCustomer(), [new InvoiceLine('X', 3, '8.13008', 23)]))->totalCents())
        ->toBe(3000);
});

it('totals every line as Moloni does, rounding base and tax per line', function () {
    $data = new InvoiceData(idCustomer(), [
        new InvoiceLine('A', 1, '10.02174', 23),
        new InvoiceLine('B', 7, '0.33333', 23),
        new InvoiceLine('C', 1, '50', 0, 'M07'),
    ]);

    expect($data->netCents())->toBe(1002 + 233 + 5000)
        ->and($data->taxCents())->toBe(231 + 54)
        ->and($data->totalCents())->toBe(1002 + 233 + 5000 + 231 + 54);
});

/**
 * @param  list<InvoiceLine>  $lines
 */
function idInvalid(array $lines, string $currency): InvoiceData
{
    return new InvoiceData(idCustomer(), $lines, $currency);
}

it('rejects invoice data without lines or with an invalid currency', function (array $lines, string $currency) {
    expect(fn () => idInvalid(array_values($lines), $currency))->toThrow(InvalidArgumentException::class);
})->with([
    'no lines' => [[], 'EUR'],
    'lowercase currency' => [[new InvoiceLine('X', 1, '1', 23)], 'eur'],
    'two letters' => [[new InvoiceLine('X', 1, '1', 23)], 'EU'],
    'four letters' => [[new InvoiceLine('X', 1, '1', 23)], 'EURO'],
]);

it('rejects payment data without a positive amount or a method', function (string $method, int $amountCents) {
    expect(fn () => new PaymentData($method, now(), $amountCents))->toThrow(InvalidArgumentException::class);
})->with([
    'zero amount' => ['transfer', 0],
    'negative amount' => ['transfer', -100],
    'empty method' => ['  ', 100],
]);

it('round-trips invoice data through an array', function () {
    $data = new InvoiceData(
        customer: idCustomer(),
        lines: [
            new InvoiceLine('Consulting', 3, '8.13008', 23, reference: 'CONS', summary: 'March'),
            new InvoiceLine('Export', 0.5, '19.99', 0, 'M07', productId: 77),
        ],
        currency: 'USD',
        yourReference: 'PO-1',
        notes: 'Thanks',
    );

    $array = $data->toArray();
    $copy = InvoiceData::fromArray(json_decode((string) json_encode($array), true));

    expect($copy->toArray())->toBe($array)
        ->and($copy->lines[0]->quantity)->toBe(3.0)
        ->and($copy->lines[0]->unitNetPrice)->toBe('8.13008')
        ->and($copy->lines[1]->productId)->toBe(77)
        ->and($copy->customer->createdAt?->format('Y-m-d H:i:s.u P'))->toBe('2025-07-04 10:11:12.345678 +01:00')
        ->and($copy->totalCents())->toBe($data->totalCents());
});

it('refuses a frozen payload without a required value', function (Closure $read, string $message) {
    expect($read)->toThrow(InvalidArgumentException::class, $message);
})->with([
    'invoice without customer' => [fn () => InvoiceData::fromArray(Arr::except(idValidData(), 'customer')), 'The frozen invoice data is missing "customer".'],
    'invoice without lines' => [fn () => InvoiceData::fromArray(Arr::except(idValidData(), 'lines')), 'The frozen invoice data is missing "lines".'],
    'invoice without currency' => [fn () => InvoiceData::fromArray(Arr::except(idValidData(), 'currency')), 'The frozen invoice data is missing "currency".'],
    'invoice with a scalar customer' => [fn () => InvoiceData::fromArray(['customer' => 'x'] + idValidData()), 'The frozen invoice data has an invalid "customer".'],
    'invoice with a scalar line' => [fn () => InvoiceData::fromArray(['lines' => ['x']] + idValidData()), 'The frozen invoice data has an invalid line.'],
    'customer without key' => [fn () => CustomerData::fromArray(Arr::except(idCustomer()->toArray(), 'key')), 'The frozen customer data is missing "key".'],
    'line without price' => [fn () => InvoiceLine::fromArray(Arr::except((new InvoiceLine('X', 1, '1', 23))->toArray(), 'unitNetPrice')), 'The frozen invoice line is missing "unitNetPrice".'],
    'payment without amount' => [fn () => PaymentData::fromArray(Arr::except((new PaymentData('card', now(), 1))->toArray(), 'amountCents')), 'The frozen payment data is missing "amountCents".'],
    'payment without date' => [fn () => PaymentData::fromArray(Arr::except((new PaymentData('card', now(), 1))->toArray(), 'paidAt')), 'The frozen payment data is missing "paidAt".'],
]);

it('round-trips customer data without optional fields', function () {
    $customer = new CustomerData('U1', null, null, null, null, null, null);

    $copy = CustomerData::fromArray($customer->toArray());

    expect($copy)->toEqual($customer);
});

it('round-trips payment data through an array', function () {
    $payment = new PaymentData('transfer', CarbonImmutable::parse('2026-05-04 10:00:00.5', 'Europe/Lisbon'), 3000, 'Ref 1');

    $array = $payment->toArray();
    $copy = PaymentData::fromArray(json_decode((string) json_encode($array), true));

    expect($copy->toArray())->toBe($array)
        ->and($copy->paidAt->equalTo($payment->paidAt))->toBeTrue()
        ->and($copy->amountCents)->toBe(3000)
        ->and(PaymentData::fromArray((new PaymentData('card', $payment->paidAt, 1))->toArray())->notes)->toBeNull();
});

it('round-trips the VIES result and reads a missing key as null', function () {
    $vies = new ViesResult(true, true, 'REQ-1', CarbonImmutable::parse('2026-11-12 10:11:12.345678', 'Europe/Lisbon'));
    $data = new InvoiceData(idCustomer(), [new InvoiceLine('X', 1, '1', 23)], vies: $vies);

    $copy = InvoiceData::fromArray(json_decode(json_encode($data->toArray(), JSON_THROW_ON_ERROR), true));

    expect($copy->vies?->valid)->toBeTrue()
        ->and($copy->vies?->requestIdentifier)->toBe('REQ-1')
        ->and($copy->vies?->checkedAt->format(CustomerData::DATE_FORMAT))->toBe($vies->checkedAt->format(CustomerData::DATE_FORMAT));

    $withoutVies = InvoiceData::fromArray(idValidData());
    expect($withoutVies->vies)->toBeNull()
        ->and($withoutVies->toArray()['vies'])->toBeNull();

    $legacy = idValidData();
    unset($legacy['vies']);
    expect(InvoiceData::fromArray($legacy)->vies)->toBeNull();
});
