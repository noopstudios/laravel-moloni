<?php

namespace Noop\Moloni\Events;

use Noop\Moloni\Models\InvoiceDocument;

/**
 * A document was cancelled in Moloni; it no longer counts, and what it settled is released.
 */
class DocumentVoided
{
    public function __construct(public readonly InvoiceDocument $document) {}
}
