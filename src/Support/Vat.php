<?php

namespace Noop\Moloni\Support;

/**
 * VAT number (NIF) helpers. The checksum is not validated: Moloni decides what it accepts.
 */
final class Vat
{
    public const FINAL_CONSUMER = '999999990';

    /**
     * Without spaces, dots or dashes, uppercased, and without a leading PT; other country
     * prefixes are kept. Null when nothing is left.
     */
    public static function normalize(?string $vat): ?string
    {
        $vat = strtoupper(str_replace([' ', '.', '-'], '', (string) $vat));

        if (str_starts_with($vat, 'PT')) {
            $vat = substr($vat, 2);
        }

        return $vat === '' ? null : $vat;
    }

    /**
     * Empty, 0 or Moloni's generic final-consumer VAT.
     */
    public static function isFinalConsumer(?string $vat): bool
    {
        return in_array(self::normalize($vat), [null, '0', self::FINAL_CONSUMER], true);
    }
}
