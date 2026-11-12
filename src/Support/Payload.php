<?php

namespace Noop\Moloni\Support;

use InvalidArgumentException;

/**
 * Reads required values from a frozen document payload, failing loudly instead of defaulting.
 */
final class Payload
{
    /**
     * @param  array<array-key, mixed>  $data
     * @param  string  $what  What is being read, for the message (e.g. "invoice data").
     */
    public static function required(array $data, string $key, string $what): mixed
    {
        if (! isset($data[$key])) {
            throw new InvalidArgumentException("The frozen {$what} is missing \"{$key}\".");
        }

        return $data[$key];
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function requiredArray(array $data, string $key, string $what): array
    {
        $value = self::required($data, $key, $what);

        if (! is_array($value)) {
            throw new InvalidArgumentException("The frozen {$what} has an invalid \"{$key}\".");
        }

        return $value;
    }
}
