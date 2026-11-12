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
    'tax on the rounded base' => [1, '10.02174', 23, 1002, 230],
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
