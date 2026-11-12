<?php

namespace Noop\Moloni\Exceptions;

use Noop\Moloni\Enums\FailureType;
use RuntimeException;

class MoloniException extends RuntimeException
{
    /**
     * @param  array<array-key, mixed>  $errors
     */
    public function __construct(
        string $message,
        public readonly FailureType $type,
        public readonly array $errors = [],
    ) {
        parent::__construct($message);
    }
}
