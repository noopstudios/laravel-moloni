<?php

use Noop\Moloni\Support\Vat;

it('normalizes a VAT number', function (?string $vat, ?string $expected) {
    expect(Vat::normalize($vat))->toBe($expected);
})->with([
    'plain' => ['509999999', '509999999'],
    'spaces, dots and dashes' => [' 509 999.99-9 ', '509999999'],
    'Portuguese prefix' => ['PT509999999', '509999999'],
    'lowercase Portuguese prefix' => ['pt 509 999 999', '509999999'],
    'foreign prefix kept' => ['es-b12345674', 'ESB12345674'],
    'null' => [null, null],
    'empty' => ['  ', null],
    'only separators' => [' .-', null],
]);

it('recognises the final consumer', function (?string $vat, bool $expected) {
    expect(Vat::isFinalConsumer($vat))->toBe($expected);
})->with([
    'null' => [null, true],
    'empty' => ['', true],
    'zero' => ['0', true],
    'generic VAT' => ['999999990', true],
    'generic VAT with prefix and spaces' => ['PT 999 999 990', true],
    'a company' => ['509999999', false],
    'a foreign company' => ['ESB12345674', false],
]);
