<?php

namespace Noop\Moloni\Data;

/**
 * The Moloni customer a document is issued to.
 */
final readonly class ResolvedCustomer
{
    public function __construct(
        public int $id,
        public string $number,
    ) {}
}
