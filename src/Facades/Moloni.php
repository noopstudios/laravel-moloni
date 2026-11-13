<?php

namespace Noop\Moloni\Facades;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use Noop\Moloni\Contracts\Invoiceable;
use Noop\Moloni\Data\CreditLine;
use Noop\Moloni\Data\PaymentData;
use Noop\Moloni\Models\InvoiceDocument;
use Noop\Moloni\Testing\MoloniFake;

/**
 * @method static InvoiceDocument invoice(Invoiceable $invoiceable, int $dueInDays = 0)
 * @method static InvoiceDocument invoiceReceipt(Invoiceable $invoiceable, PaymentData $payment)
 * @method static InvoiceDocument proforma(Invoiceable $invoiceable, string $idempotencyKey)
 * @method static InvoiceDocument convert(InvoiceDocument $proforma, ?PaymentData $payment = null, int $dueInDays = 0)
 * @method static InvoiceDocument receipt(Model $owner, list<array{0: InvoiceDocument, 1: int}> $allocations, PaymentData $payment, ?string $idempotencyKey = null)
 * @method static InvoiceDocument creditNote(InvoiceDocument $document, list<CreditLine>|null $lines = null, ?string $idempotencyKey = null)
 * @method static InvoiceDocument cancel(InvoiceDocument $document, string $reason)
 * @method static InvoiceDocument reissue(InvoiceDocument $document, ?PaymentData $payment = null, ?int $dueInDays = null)
 * @method static void retry(InvoiceDocument $document)
 * @method static void reconcile(InvoiceDocument $document)
 * @method static string pdfLink(InvoiceDocument $document)
 *
 * @see \Noop\Moloni\Moloni
 */
class Moloni extends Facade
{
    /**
     * Replaces the manager with a fake that issues every request on the spot, without HTTP or the queue.
     */
    public static function fake(): MoloniFake
    {
        $fake = app(MoloniFake::class);
        static::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return \Noop\Moloni\Moloni::class;
    }
}
