<?php

namespace Noop\Moloni\Contracts;

use Noop\Moloni\Data\InvoiceData;

/**
 * An Eloquent model documents are issued for (an order, a booking, a subscription period).
 * Implementations are models, so the package also relies on getMorphClass() and getKey().
 */
interface Invoiceable
{
    /**
     * The customer and lines to bill, read once when a document is requested and frozen in its row.
     */
    public function toInvoiceData(): InvoiceData;

    /**
     * The application's reference for the document, e.g. the order number.
     */
    public function invoiceReference(): string;

    /**
     * The application's stable id for the billing customer.
     */
    public function customerKey(): string;
}
