<?php

namespace Noop\Moloni\Events;

use Noop\Moloni\Models\InvoiceDocument;

/**
 * A document failed or was left unknown (its outcome in Moloni is uncertain); see its status and error_* columns.
 */
class DocumentFailed
{
    public function __construct(public readonly InvoiceDocument $document) {}
}
