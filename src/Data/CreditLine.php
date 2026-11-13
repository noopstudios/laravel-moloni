<?php

namespace Noop\Moloni\Data;

use InvalidArgumentException;
use Noop\Moloni\Support\Message;

/**
 * A quantity to credit on one line of an issued document.
 */
final readonly class CreditLine
{
    /**
     * @param  int  $lineIndex  The line's position (0-based) among the document's lines, as they were requested.
     * @param  float  $quantity  How much of the line to credit; at most what is still creditable on it.
     */
    public function __construct(
        public int $lineIndex,
        public float $quantity,
    ) {
        if ($lineIndex < 0) {
            throw new InvalidArgumentException(Message::get('A credit line index cannot be negative.'));
        }

        if (! is_finite($quantity) || $quantity <= 0) {
            throw new InvalidArgumentException(Message::get('The quantity to credit on line :line must be greater than zero.', ['line' => $lineIndex]));
        }
    }
}
