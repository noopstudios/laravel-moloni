<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Noop\Moloni\Data\InvoiceData;
use Noop\Moloni\Enums\DocumentStatus;
use Noop\Moloni\Enums\DocumentType;
use Noop\Moloni\Models\InvoiceDocument;
use Noop\Moloni\Models\InvoiceDocumentLink;
use Noop\Moloni\Tests\Fixtures\Order;

beforeEach(function () {
    Order::createTable();
});

function idmSettle(InvoiceDocument $settler, InvoiceDocument $invoice, int $cents): void
{
    InvoiceDocumentLink::create([
        'document_id' => $settler->id,
        'associated_document_id' => $invoice->id,
        'amount_cents' => $cents,
    ]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function idmIssuedInvoice(int $totalCents, string $issuedAt, array $attributes = []): InvoiceDocument
{
    return InvoiceDocument::factory()->invoice()->issued()->create([
        'customer_vat' => '509999999',
        'expected_total_cents' => $totalCents,
        'issued_at' => Carbon::parse($issuedAt),
        ...$attributes,
    ]);
}

/**
 * Invoices for one VAT with every kind of settling document; returns them by name.
 *
 * @return array<string, InvoiceDocument>
 */
function idmFixtures(): array
{
    $docs = [
        'partly paid' => idmIssuedInvoice(10000, '2026-05-02 10:00:00'),
        'paid' => idmIssuedInvoice(2000, '2026-05-01 10:00:00', ['customer_vat' => 'PT 509 999 999']),
        'unpaid' => idmIssuedInvoice(1500, '2026-05-01 09:00:00'),
        'overpaid' => idmIssuedInvoice(1000, '2026-05-03 10:00:00'),
        'pending' => InvoiceDocument::factory()->invoice()->create(['customer_vat' => '509999999', 'expected_total_cents' => 1000]),
        'other vat' => idmIssuedInvoice(1000, '2026-05-01 10:00:00', ['customer_vat' => '501111111']),
        'invoice-receipt' => InvoiceDocument::factory()->invoiceReceipt()->issued()->create(['customer_vat' => '509999999', 'expected_total_cents' => 1000]),
        'final consumer' => idmIssuedInvoice(1000, '2026-05-01 10:00:00', ['customer_vat' => '999999990']),
    ];

    $invoice = $docs['partly paid'];
    idmSettle(InvoiceDocument::factory()->receipt()->issued()->create(), $invoice, 3000);
    idmSettle(InvoiceDocument::factory()->creditNote()->create(), $invoice, 1000);
    idmSettle(InvoiceDocument::factory()->receipt()->unknown()->create(), $invoice, 500);
    idmSettle(InvoiceDocument::factory()->creditNote()->create(['status' => DocumentStatus::Issuing]), $invoice, 500);
    idmSettle(InvoiceDocument::factory()->receipt()->failed()->create(), $invoice, 5000);
    idmSettle(InvoiceDocument::factory()->receipt()->create(['status' => DocumentStatus::Superseded]), $invoice, 5000);
    idmSettle(InvoiceDocument::factory()->creditNote()->create(['status' => DocumentStatus::Voided]), $invoice, 5000);
    // An invoice that points at another (a conversion) does not settle it.
    idmSettle(InvoiceDocument::factory()->invoice()->issued()->create(), $invoice, 9999);

    idmSettle(InvoiceDocument::factory()->receipt()->issued()->create(), $docs['paid'], 2000);
    idmSettle(InvoiceDocument::factory()->receipt()->issued()->create(), $docs['overpaid'], 1500);

    return $docs;
}

it('allows only one live invoice or invoice-receipt per invoiceable', function () {
    $order = Order::create();
    $key = InvoiceDocument::activeKeyFor($order);

    InvoiceDocument::factory()->invoice()->create(['active_key' => $key]);

    expect(fn () => InvoiceDocument::factory()->invoiceReceipt()->create(['active_key' => $key]))
        ->toThrow(QueryException::class);
});

it('frees the active key for a new document once released', function () {
    $order = Order::create();
    $failed = InvoiceDocument::factory()->invoice()->failed()->create(['active_key' => InvoiceDocument::activeKeyFor($order)]);

    $failed->releaseActiveKey()->save();
    $next = InvoiceDocument::factory()->invoice()->create(['active_key' => InvoiceDocument::activeKeyFor($order)]);

    expect($failed->fresh()?->active_key)->toBeNull()
        ->and($next->active_key)->toBe($order->getMorphClass().':'.$order->id.':invoice');
});

it('rejects a second row with the same operation key', function () {
    InvoiceDocument::factory()->create(['operation_key' => 'invoice:order:1:1']);

    expect(fn () => InvoiceDocument::factory()->create(['operation_key' => 'invoice:order:1:1']))
        ->toThrow(QueryException::class);
});

it('rejects the same Moloni document twice in one company', function () {
    InvoiceDocument::factory()->issued()->create(['company_id' => 5, 'document_id' => 900]);
    InvoiceDocument::factory()->issued()->create(['company_id' => 6, 'document_id' => 900]);

    expect(fn () => InvoiceDocument::factory()->issued()->create(['company_id' => 5, 'document_id' => 900]))
        ->toThrow(QueryException::class);
});

it('builds operation keys from the type, the invoiceable and a suffix', function () {
    $order = Order::create();

    expect(InvoiceDocument::operationKey(DocumentType::InvoiceReceipt, $order, 2))
        ->toBe('invoice_receipt:'.$order->getMorphClass().':'.$order->id.':2')
        ->and(InvoiceDocument::operationKey(DocumentType::Invoice, $order, 'a'))
        ->toBe('invoice:'.$order->getMorphClass().':'.$order->id.':a');
});

it('normalizes the customer VAT when stored', function () {
    $document = InvoiceDocument::factory()->create(['customer_vat' => 'pt 509.999-999']);

    expect($document->fresh()?->customer_vat)->toBe('509999999');
});

it('casts its columns', function () {
    $order = Order::create();
    $document = InvoiceDocument::factory()->invoice()->issued()->create([
        'invoiceable_type' => $order->getMorphClass(),
        'invoiceable_id' => $order->id,
        'vies' => ['valid' => true],
        'payload' => $order->toInvoiceData()->toArray(),
        'due_at' => '2026-06-01',
    ])->fresh();

    expect($document?->type)->toBe(DocumentType::Invoice)
        ->and($document?->status)->toBe(DocumentStatus::Issued)
        ->and($document?->issued_at)->toBeInstanceOf(DateTimeInterface::class)
        ->and($document?->due_at?->toDateString())->toBe('2026-06-01')
        ->and($document?->vies)->toBe(['valid' => true])
        ->and(InvoiceData::fromArray($document->payload ?? [])->toArray())->toBe($order->toInvoiceData()->toArray())
        ->and($document?->invoiceable)->toBeInstanceOf(Order::class)
        ->and($document?->invoiceable?->getKey())->toBe($order->id)
        ->and($document?->attempts)->toBe(1);
});

it('defaults to euros and no attempts', function () {
    $document = InvoiceDocument::create([
        'type' => DocumentType::Invoice,
        'operation_key' => 'invoice:order:1:1',
        'status' => DocumentStatus::Pending,
        'reference' => 'ORDER-1',
        'expected_total_cents' => 100,
    ])->fresh();

    expect($document?->currency)->toBe('EUR')
        ->and($document?->attempts)->toBe(0);
});

it('links documents in both directions', function () {
    $invoice = InvoiceDocument::factory()->invoice()->issued()->create();
    $receipt = InvoiceDocument::factory()->receipt()->issued()->create();
    idmSettle($receipt, $invoice, 1200);

    $link = $receipt->links()->sole();

    expect($link->amount_cents)->toBe(1200)
        ->and($link->document?->is($receipt))->toBeTrue()
        ->and($link->associatedDocument?->is($invoice))->toBeTrue()
        ->and($invoice->settledBy()->sole()->is($link))->toBeTrue()
        ->and($invoice->links()->count())->toBe(0);
});

it('computes outstanding from issued and in-flight receipts and credit notes', function () {
    $docs = idmFixtures();

    expect($docs['partly paid']->outstandingCents())->toBe(10000 - 3000 - 1000 - 500 - 500)
        ->and($docs['unpaid']->outstandingCents())->toBe(1500)
        ->and($docs['paid']->outstandingCents())->toBe(0)
        ->and($docs['overpaid']->outstandingCents())->toBe(0);
});

it('ignores failed, superseded and voided documents in outstanding', function () {
    $invoice = idmIssuedInvoice(4000, '2026-05-01 10:00:00');
    idmSettle(InvoiceDocument::factory()->receipt()->failed()->create(), $invoice, 1000);
    idmSettle(InvoiceDocument::factory()->receipt()->create(['status' => DocumentStatus::Superseded]), $invoice, 1000);
    idmSettle(InvoiceDocument::factory()->creditNote()->create(['status' => DocumentStatus::Voided]), $invoice, 1000);

    expect($invoice->outstandingCents())->toBe(4000)
        ->and(InvoiceDocument::openForVat('509999999')->sole()->is($invoice))->toBeTrue();
});

it('has no outstanding amount for documents that are not invoices', function () {
    $docs = idmFixtures();

    expect($docs['invoice-receipt']->outstandingCents())->toBe(0)
        ->and(InvoiceDocument::factory()->proforma()->create(['expected_total_cents' => 500])->outstandingCents())->toBe(0);
});

it('lists open invoices for a normalized VAT, oldest first', function () {
    $docs = idmFixtures();

    expect(InvoiceDocument::openForVat('PT 509-999-999')->pluck('id')->all())
        ->toBe([$docs['unpaid']->id, $docs['partly paid']->id])
        ->and(InvoiceDocument::openForVat('509999999')->paginate(1)->total())->toBe(2);
});

it('agrees with outstandingCents on which invoices are open', function () {
    $docs = idmFixtures();

    $open = collect($docs)
        ->filter(fn (InvoiceDocument $doc) => $doc->type === DocumentType::Invoice
            && $doc->status === DocumentStatus::Issued
            && $doc->customer_vat === '509999999'
            && $doc->outstandingCents() > 0)
        ->pluck('id')
        ->sort()
        ->values()
        ->all();

    expect(InvoiceDocument::openForVat('509999999')->pluck('id')->sort()->values()->all())->toBe($open);
});

it('never groups final consumers by VAT', function (string $vat) {
    idmFixtures();

    expect(InvoiceDocument::openForVat($vat)->count())->toBe(0);
})->with(['999999990', '', '0']);

it('builds every factory state', function () {
    expect(InvoiceDocument::factory()->invoice()->create()->type)->toBe(DocumentType::Invoice)
        ->and(InvoiceDocument::factory()->invoiceReceipt()->create()->type)->toBe(DocumentType::InvoiceReceipt)
        ->and(InvoiceDocument::factory()->receipt()->create()->type)->toBe(DocumentType::Receipt)
        ->and(InvoiceDocument::factory()->creditNote()->create()->type)->toBe(DocumentType::CreditNote)
        ->and(InvoiceDocument::factory()->proforma()->create()->type)->toBe(DocumentType::Proforma)
        ->and(InvoiceDocument::factory()->create()->status)->toBe(DocumentStatus::Pending)
        ->and(InvoiceDocument::factory()->issued()->create()->document_id)->not->toBeNull()
        ->and(InvoiceDocument::factory()->failed()->create()->status)->toBe(DocumentStatus::Failed)
        ->and(InvoiceDocument::factory()->unknown()->create()->status)->toBe(DocumentStatus::Unknown);
});

it('labels and colours types and statuses in Portuguese', function () {
    app()->setLocale('pt');

    expect(array_map(fn (DocumentType $t) => $t->getLabel(), DocumentType::cases()))
        ->toBe(['Fatura pró-forma', 'Fatura', 'Fatura-recibo', 'Recibo', 'Nota de crédito'])
        ->and(array_map(fn (DocumentStatus $s) => $s->getLabel(), DocumentStatus::cases()))
        ->toBe(['Pendente', 'A emitir', 'Emitido', 'Falhado', 'Por confirmar', 'Substituído', 'Anulado'])
        ->and(array_map(fn (DocumentStatus $s) => $s->getColor(), DocumentStatus::cases()))
        ->toBe(['gray', 'info', 'success', 'danger', 'warning', 'gray', 'gray']);
});

it('knows live statuses and debt-settling types', function () {
    expect(array_values(array_map(fn (DocumentStatus $s) => $s->value, array_filter(DocumentStatus::cases(), fn (DocumentStatus $s) => $s->isLive()))))
        ->toBe(['pending', 'issuing', 'issued', 'unknown'])
        ->and(array_values(array_map(fn (DocumentType $t) => $t->value, array_filter(DocumentType::cases(), fn (DocumentType $t) => $t->settlesDebt()))))
        ->toBe(['receipt', 'credit_note']);
});
