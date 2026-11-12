<?php

use Noop\Moloni\Data\InvoiceLine;

it('totals a line the way Moloni does, rounding base and tax per line', function (float $quantity, string $price, float $rate, int $net, int $tax) {
    $line = new InvoiceLine('Consulting', $quantity, $price, $rate);

    expect($line->lineNetCents())->toBe($net)
        ->and($line->lineTaxCents())->toBe($tax);
})->with([
    'three at 8.13008, 23%' => [3, '8.13008', 23, 2439, 561],
    'one at 0.333, 23%' => [1, '0.333', 23, 33, 8],
    'a half cent rounds up' => [1, '1.005', 0, 101, 0],
    'tax on the unrounded base' => [1, '10.02174', 23, 1002, 231],
    'seven at 0.33333, 23%' => [7, '0.33333', 23, 233, 54],
    'half a cent' => [1, '0.005', 23, 1, 0],
    'three at 1.11111, 6%' => [3, '1.11111', 6, 333, 20],
    'a rate that is zero within tolerance' => [1, '100', 0.0001, 10000, 0],
    'fractional quantity' => [0.5, '19.99', 6, 1000, 60],
    'free line' => [2, '0', 23, 0, 0],
]);

it('rejects an invalid line', function (string $name, float $quantity, string $price, float $rate) {
    expect(fn () => new InvoiceLine($name, $quantity, $price, $rate))->toThrow(InvalidArgumentException::class);
})->with([
    'empty name' => ['  ', 1, '10', 23],
    'zero quantity' => ['Fee', 0, '10', 23],
    'negative quantity' => ['Fee', -1, '10', 23],
    'infinite quantity' => ['Fee', INF, '10', 23],
    'non-numeric price' => ['Fee', 1, 'ten', 23],
    'negative price' => ['Fee', 1, '-10', 23],
    'more than five decimals' => ['Fee', 1, '8.130081', 23],
    'exponent price' => ['Fee', 1, '1e3', 23],
    'negative rate' => ['Fee', 1, '10', -23],
    'not-a-number rate' => ['Fee', 1, '10', NAN],
]);

it('knows a zero-rate line within the rate tolerance', function () {
    expect((new InvoiceLine('Export', 1, '10', 0))->isZeroRated())->toBeTrue()
        ->and((new InvoiceLine('Export', 1, '10', 1e-15))->isZeroRated())->toBeTrue()
        ->and((new InvoiceLine('Fee', 1, '10', 6))->isZeroRated())->toBeFalse()
        ->and(InvoiceLine::isZeroRate(0.0005))->toBeTrue()
        ->and(InvoiceLine::isZeroRate(0.01))->toBeFalse();
});
