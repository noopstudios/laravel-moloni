<?php

namespace Noop\Moloni\Support;

/**
 * Translated text for messages the package stores (e.g. a document's error_message).
 */
final class Message
{
    /**
     * @param  array<string, scalar|null>  $replace
     */
    public static function get(string $key, array $replace = []): string
    {
        $text = __($key, $replace);

        // __() returns an array when the key names a group of PHP-file translations.
        return is_string($text) ? $text : $key;
    }
}
