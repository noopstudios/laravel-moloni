<?php

namespace Noop\Moloni\Events;

use Noop\Moloni\Models\InvoiceDocument;

/**
 * A document was issued in Moloni, or an existing Moloni document was adopted for it. Its error_type may still flag total_mismatch or readback_failed.
 */
class DocumentIssued
{
    public function __construct(public readonly InvoiceDocument $document) {}
}
