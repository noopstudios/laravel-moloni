<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Noop\Moloni\Data\CustomerData;
use Noop\Moloni\Data\InvoiceData;
use Noop\Moloni\Data\InvoiceLine;
use Noop\Moloni\Data\PaymentData;
use Noop\Moloni\Enums\DocumentStatus;
use Noop\Moloni\Enums\DocumentType;
use Noop\Moloni\Enums\FailureType;
use Noop\Moloni\Events\DocumentFailed;
use Noop\Moloni\Events\DocumentIssued;
use Noop\Moloni\Facades\Moloni;
use Noop\Moloni\Jobs\IssueDocument;
use Noop\Moloni\Models\InvoiceDocument;
use Noop\Moloni\Models\InvoiceDocumentLink;
use Noop\Moloni\Tax\ViesResult;
use Noop\Moloni\Tests\Fixtures\Order;

use function Pest\Laravel\travelTo;

beforeEach(function () {
    Http::preventStrayRequests();
    useIssueSettings(['document_sets' => ['proforma' => 43, 'invoice' => 41, 'invoice_receipt' => 42]]);
    Order::$invoiceDataUsing = null;
    travelTo(Carbon::parse('2026-05-04 10:00:00'));
    Event::fake([DocumentIssued::class, DocumentFailed::class]);
});

/**
 * Moloni while issuing pro-formas and their conversions: pro-formas 6001, 6002, … and invoices
 * (or invoice-receipts) 7001, 7002, … are inserted in turn; getAll lists what local rows recorded;
 * getOne reads back any id with the default total.
 *
 * @param  array<string, mixed>  $overrides
 */
function pfFake(array $overrides = []): void
{
    $recorded = fn (DocumentType $type) => fn (Request $request) => Http::response(InvoiceDocument::query()
        ->where('type', $type)
        ->whereNotNull('document_id')
        ->get()
        ->map(fn (InvoiceDocument $document) => moloniDocumentRow([
            'document_id' => $document->document_id,
            'document_set_id' => $document->document_set_id,
            'our_reference' => $document->reference,
        ]))
        ->all());

    fakeMoloniIssuing([
        '*/proFormaInvoices/insert/*' => Http::sequence()
            ->push(['valid' => 1, 'document_id' => 6001])
            ->push(['valid' => 1, 'document_id' => 6002]),
        '*/proFormaInvoices/getAll/*' => $recorded(DocumentType::Proforma),
        '*/invoices/insert/*' => Http::sequence()
            ->push(['valid' => 1, 'document_id' => 7001])
            ->push(['valid' => 1, 'document_id' => 7002]),
        '*/invoiceReceipts/insert/*' => ['valid' => 1, 'document_id' => 7001],
        '*/documents/getOne/*' => fn (Request $request) => Http::response(moloniDocumentRow(['document_id' => $request['document_id']])),
        ...$overrides,
    ]);
}

/**
 * A pro-forma requested with the queue faked, then given $attributes.
 *
 * @param  array<string, mixed>  $attributes
 */
function pfWith(array $attributes): InvoiceDocument
{
    $proforma = Moloni::proforma(Order::create(['customer_vat' => '509999999']), 'quote-1');
    $proforma->fill($attributes)->save();

    return $proforma;
}

function pfPayment(int $cents = 12300): PaymentData
{
    return new PaymentData('transfer', Carbon::parse('2026-05-04 09:30:00'), $cents);
}

/**
 * An issued pro-forma (Moloni id 6001) for a new order, with Moloni faked by pfFake($overrides).
 *
 * @param  array<string, mixed>  $overrides
 */
function pfIssued(?Order $order = null, string $key = 'quote-1', array $overrides = []): InvoiceDocument
{
    pfFake($overrides);
    $proforma = Moloni::proforma($order ?? Order::create(['customer_vat' => '509999999']), $key)->refresh();
    expect($proforma->status)->toBe(DocumentStatus::Issued);

    return $proforma;
}

it('issues a pro-forma without a payment block and with a 30-day expiration', function () {
    pfFake();
    $order = Order::create(['customer_vat' => '509999999']);

    $proforma = Moloni::proforma($order, 'quote-1')->refresh();

    $inserts = moloniRequests('proFormaInvoices/insert');
    expect($inserts)->toHaveCount(1)
        ->and($inserts[0]->data())->toBe([
            'company_id' => 5,
            'date' => '2026-05-04',
            'expiration_date' => '2026-06-03',
            'document_set_id' => 43,
            'customer_id' => 301,
            'our_reference' => '#ORDER-1',
            'status' => 1,
            'products' => [[
                'product_id' => 900,
                'name' => 'Consulting',
                'summary' => '',
                'qty' => 1.0,
                'price' => '100',
                'taxes' => [['tax_id' => 82, 'value' => 23.0, 'order' => 1, 'cumulative' => 0]],
            ]],
        ])
        ->and(moloniRequests('proFormaInvoices/getAll'))->toHaveCount(1)
        ->and($proforma->type)->toBe(DocumentType::Proforma)
        ->and($proforma->status)->toBe(DocumentStatus::Issued)
        ->and($proforma->document_id)->toBe(6001)
        ->and($proforma->active_key)->toBeNull()
        ->and($proforma->operation_key)->toBe(InvoiceDocument::operationKey(DocumentType::Proforma, $order, 'quote-1'))
        ->and($proforma->reference)->toBe('#ORDER-1')
        ->and($proforma->expected_total_cents)->toBe(12300)
        ->and($proforma->due_at?->toDateString())->toBe('2026-06-03')
        ->and($proforma->payload)->toMatchArray(['payment' => null, 'due_in_days' => 30]);
});

it('takes the pro-forma validity from the config', function () {
    pfFake();
    config(['moloni.proforma_valid_days' => 15]);

    Moloni::proforma(Order::create(['customer_vat' => '509999999']), 'quote-1');

    expect(moloniRequests('proFormaInvoices/insert')[0]['expiration_date'])->toBe('2026-05-19')
        ->and(config('moloni.proforma_valid_days'))->toBe(15);
});

it('records a pending pro-forma without calling Moloni', function () {
    Http::fake();
    Queue::fake();

    $proforma = Moloni::proforma(Order::create(['customer_vat' => '509999999']), 'quote-1');

    Http::assertNothingSent();
    expect($proforma->status)->toBe(DocumentStatus::Pending)
        ->and($proforma->document_set_id)->toBe(43);
    Queue::assertPushed(IssueDocument::class, fn (IssueDocument $job) => $job->document->is($proforma) && $job->afterCommit === true);
});

it('returns the same pro-forma for a repeated idempotency key', function () {
    pfFake();
    $order = Order::create(['customer_vat' => '509999999']);
    $first = Moloni::proforma($order, 'quote-1');
    Queue::fake();

    $again = Moloni::proforma($order, 'quote-1');

    expect($again->is($first))->toBeTrue()
        ->and($again->status)->toBe(DocumentStatus::Issued)
        ->and(InvoiceDocument::count())->toBe(1)
        ->and(moloniRequests('proFormaInvoices/insert'))->toHaveCount(1);
    Queue::assertNothingPushed();
});

it('sends a failed pro-forma again when its key is requested again', function () {
    Queue::fake();
    $order = Order::create(['customer_vat' => '509999999']);
    $first = Moloni::proforma($order, 'quote-1');
    $first->fill(['status' => DocumentStatus::Failed, 'error_type' => 'validation', 'attempts' => 3])->save();
    Queue::fake();

    $again = Moloni::proforma($order, 'quote-1');

    expect($again->is($first))->toBeTrue()
        ->and($again->status)->toBe(DocumentStatus::Pending)
        ->and($again->attempts)->toBe(0)
        ->and($again->error_type)->toBeNull()
        ->and(InvoiceDocument::count())->toBe(1);
    Queue::assertPushed(IssueDocument::class, fn (IssueDocument $job) => $job->document->is($first));
});

it('refuses an empty pro-forma key', function () {
    Queue::fake();

    expect(fn () => Moloni::proforma(Order::create(), ' '))->toThrow(InvalidArgumentException::class);
    expect(InvoiceDocument::count())->toBe(0);
});

it('allows a second pro-forma with a different key', function () {
    $order = Order::create(['customer_vat' => '509999999']);
    $first = pfIssued($order, 'quote-1');

    // Moloni lists the first under the same reference; it is recorded on its own row.
    $second = Moloni::proforma($order, 'quote-2')->refresh();

    expect($second->is($first))->toBeFalse()
        ->and($second->status)->toBe(DocumentStatus::Issued)
        ->and($second->document_id)->toBe(6002)
        ->and($second->reference)->toBe($first->reference)
        ->and($first->fresh()?->document_id)->toBe(6001)
        ->and(moloniRequests('proFormaInvoices/insert'))->toHaveCount(2)
        ->and(InvoiceDocument::count())->toBe(2);
});

it('leaves a pro-forma unknown when its insert response is lost', function () {
    pfFake(['*/proFormaInvoices/insert/*' => Http::response('Bad gateway', 502)]);

    $proforma = Moloni::proforma(Order::create(['customer_vat' => '509999999']), 'quote-1')->refresh();

    expect($proforma->status)->toBe(DocumentStatus::Unknown)
        ->and($proforma->document_id)->toBeNull()
        ->and(moloniRequests('proFormaInvoices/insert'))->toHaveCount(1);
});

it('does not hold the invoiceable\'s live invoice slot', function () {
    Queue::fake();
    $order = Order::create(['customer_vat' => '509999999']);
    $proforma = Moloni::proforma($order, 'quote-1');

    $invoice = Moloni::invoice($order);

    expect($invoice->is($proforma))->toBeFalse()
        ->and($invoice->operation_key)->toBe(InvoiceDocument::operationKey(DocumentType::Invoice, $order, 1));
});

it('refuses to reissue a pro-forma', function () {
    $proforma = pfIssued();

    $e = catchMoloniException(fn () => Moloni::reissue($proforma));

    expect($e->type)->toBe(FailureType::Validation)
        ->and($e->getMessage())->toContain('new key')
        ->and($proforma->fresh()?->status)->toBe(DocumentStatus::Issued)
        ->and(InvoiceDocument::count())->toBe(1);
});

it('converts a pro-forma into an invoice linked to it', function () {
    $order = Order::create(['customer_vat' => '509999999']);
    $proforma = pfIssued($order);

    $invoice = Moloni::convert($proforma, dueInDays: 30)->refresh();

    $inserts = moloniRequests('invoices/insert');
    expect($inserts)->toHaveCount(1);
    $sent = $inserts[0]->data();
    expect($sent['associated_documents'])->toBe([['associated_id' => 6001, 'value' => 123.0]])
        ->and($sent['maturity_date_id'])->toBe(12)
        ->and($sent['expiration_date'])->toBe('2026-06-03')
        ->and($sent['document_set_id'])->toBe(41)
        ->and($sent['our_reference'])->toBe('#ORDER-1')
        ->and($sent)->not->toHaveKey('payments')
        ->and($invoice->type)->toBe(DocumentType::Invoice)
        ->and($invoice->status)->toBe(DocumentStatus::Issued)
        ->and($invoice->document_id)->toBe(7001)
        ->and($invoice->expected_total_cents)->toBe($proforma->expected_total_cents)
        ->and($invoice->active_key)->toBe(InvoiceDocument::activeKeyFor($order))
        ->and($invoice->payload['converts'] ?? null)->toBe(['proforma_document_id' => $proforma->id, 'moloni_document_id' => 6001])
        ->and($invoice->links()->sole()->only(['associated_document_id', 'amount_cents']))
        ->toBe(['associated_document_id' => $proforma->id, 'amount_cents' => 12300])
        ->and($proforma->fresh()?->status)->toBe(DocumentStatus::Issued);
});

it('converts a pro-forma into an invoice-receipt when a payment is given', function () {
    $proforma = pfIssued();

    $receipt = Moloni::convert($proforma, pfPayment())->refresh();

    $inserts = moloniRequests('invoiceReceipts/insert');
    expect($inserts)->toHaveCount(1)
        ->and(moloniRequests('invoices/insert'))->toHaveCount(0)
        ->and($inserts[0]['associated_documents'])->toBe([['associated_id' => 6001, 'value' => 123.0]])
        ->and($inserts[0]['payments'])->toBe([['payment_method_id' => 22, 'date' => '2026-05-04', 'value' => 123.0]])
        ->and($receipt->type)->toBe(DocumentType::InvoiceReceipt)
        ->and($receipt->status)->toBe(DocumentStatus::Issued)
        ->and($receipt->links()->sole()->associated_document_id)->toBe($proforma->id);
});

it('converts what the customer was quoted, even when the order changed since', function () {
    $proforma = pfIssued();
    Order::$invoiceDataUsing = fn (Order $order) => new InvoiceData(
        new CustomerData('C'.$order->id, '501111111', 'Other Lda', null, null, null, null),
        [new InvoiceLine('Consulting', 2, '100', 23)],
    );
    Queue::fake();

    $invoice = Moloni::convert($proforma);

    expect($invoice->payload['invoice'] ?? null)->toBe($proforma->payload['invoice'] ?? [])
        ->and($invoice->expected_total_cents)->toBe(12300)
        ->and($invoice->customer_vat)->toBe('509999999');
});

it('returns the same document when a pro-forma is converted twice', function () {
    $proforma = pfIssued();
    $first = Moloni::convert($proforma);
    Queue::fake();

    $again = Moloni::convert($proforma);

    expect($again->is($first))->toBeTrue()
        ->and(InvoiceDocument::count())->toBe(2)
        ->and(InvoiceDocumentLink::count())->toBe(1)
        ->and(moloniRequests('invoices/insert'))->toHaveCount(1);
    Queue::assertNothingPushed();
});

it('refuses to convert a pro-forma that is not issued', function (Closure $make) {
    Queue::fake();
    $document = $make();

    $e = catchMoloniException(fn () => Moloni::convert($document));

    expect($e->type)->toBe(FailureType::Validation)
        ->and(InvoiceDocument::where('type', '!=', $document->type)->count())->toBe(0)
        ->and(InvoiceDocumentLink::count())->toBe(0);
})->with([
    'pending' => [fn () => Moloni::proforma(Order::create(['customer_vat' => '509999999']), 'quote-1')],
    'failed' => [fn () => pfWith(['status' => DocumentStatus::Failed])],
    'unknown' => [fn () => pfWith(['status' => DocumentStatus::Unknown])],
    'issued without a Moloni id' => [fn () => pfWith(['status' => DocumentStatus::Issued])],
    'another company' => [fn () => pfWith(['status' => DocumentStatus::Issued, 'document_id' => 6001, 'company_id' => 6])],
    'an invoice' => [fn () => tap(Moloni::invoice(Order::create(['customer_vat' => '509999999'])), fn (InvoiceDocument $invoice) => $invoice->fill(['status' => DocumentStatus::Issued, 'document_id' => 7001])->save())],
]);

it('refuses to convert when the invoiceable already has a live invoice', function () {
    $order = Order::create(['customer_vat' => '509999999']);
    $proforma = pfIssued($order);
    Queue::fake();
    $invoice = Moloni::invoice($order);

    $e = catchMoloniException(fn () => Moloni::convert($proforma));

    expect($e->type)->toBe(FailureType::Validation)
        ->and($invoice->fresh()?->status)->toBe(DocumentStatus::Pending)
        ->and(InvoiceDocument::count())->toBe(2)
        ->and(InvoiceDocumentLink::count())->toBe(0);
    Queue::assertPushed(IssueDocument::class, 1);
});

it('refuses a conversion it cannot issue without creating a row', function (?PaymentData $payment, int $dueInDays, string $message) {
    $proforma = pfIssued();
    Queue::fake();

    $e = catchMoloniException(fn () => Moloni::convert($proforma, $payment, $dueInDays));

    expect($e->type)->toBe(FailureType::Validation)
        ->and($e->getMessage())->toContain($message)
        ->and(InvoiceDocument::count())->toBe(1);
    Queue::assertNothingPushed();
})->with([
    'payment below the total' => [fn () => pfPayment(12299), 0, 'does not match'],
    'no maturity date for the term' => [null, 45, '45'],
]);

it('fails the conversion when Moloni refuses the association, without sending it unassociated', function () {
    $proforma = pfIssued(overrides: ['*/invoices/insert/*' => [[
        ['code' => '10 associated_id', 'description' => 'The associated document is not valid.'],
        ['code' => '2 value 0 null null 0 0', 'description' => "Field 'value' must be float, greater or equal to 0, lesser or equal to 0"],
    ]]]);

    $invoice = Moloni::convert($proforma)->refresh();

    expect($invoice->status)->toBe(DocumentStatus::Failed)
        ->and($invoice->error_type)->toBe(FailureType::Validation->value)
        ->and($invoice->error_message)->toContain('The associated document is not valid.')
        ->and($invoice->active_key)->toBeNull()
        ->and(moloniRequests('invoices/insert'))->toHaveCount(1)
        ->and(moloniRequests('invoices/insert')[0]['associated_documents'])->toBe([['associated_id' => 6001, 'value' => 123.0]]);

    // A failed conversion no longer counts: the pro-forma can be converted again.
    Queue::fake();
    $again = Moloni::convert($proforma);

    expect($again->is($invoice))->toBeFalse()
        ->and($again->status)->toBe(DocumentStatus::Pending)
        ->and($again->links()->sole()->associated_document_id)->toBe($proforma->id);
});

it('leaves a conversion unknown when its insert response is lost', function () {
    $proforma = pfIssued(overrides: ['*/invoices/insert/*' => Http::response('Bad gateway', 502)]);

    $invoice = Moloni::convert($proforma)->refresh();

    expect($invoice->status)->toBe(DocumentStatus::Unknown)
        ->and($invoice->document_id)->toBeNull()
        ->and(moloniRequests('invoices/insert'))->toHaveCount(1);
});

it('keeps the association when a converted invoice is reissued', function () {
    $proforma = pfIssued();
    $old = Moloni::convert($proforma)->refresh();
    expect($old->document_id)->toBe(7001);

    $new = Moloni::reissue($old)->refresh();

    $inserts = moloniRequests('invoices/insert');
    expect($inserts)->toHaveCount(2)
        ->and($inserts[1]['associated_documents'])->toBe([['associated_id' => 6001, 'value' => 123.0]])
        ->and($old->fresh()?->status)->toBe(DocumentStatus::Superseded)
        ->and($new->status)->toBe(DocumentStatus::Issued)
        ->and($new->document_id)->toBe(7002)
        ->and($new->payload['converts'] ?? null)->toBe($old->payload['converts'] ?? [])
        ->and($new->links()->sole()->associated_document_id)->toBe($proforma->id)
        ->and($old->links()->count())->toBe(1);

    // The reissued invoice is now the pro-forma's conversion.
    expect(Moloni::convert($proforma)->is($new))->toBeTrue();
});

it('never counts a pro-forma link against what the invoice owes', function () {
    $proforma = pfIssued();

    $invoice = Moloni::convert($proforma)->refresh();

    expect($invoice->outstandingCents())->toBe(12300)
        ->and(InvoiceDocument::outstandingCentsForUpdate([$invoice]))->toBe([$invoice->id => 12300])
        ->and($proforma->fresh()?->outstandingCents())->toBe(0);
});

it('stores the VIES evidence of the request on the row', function (Closure $request) {
    Queue::fake();
    $vies = new ViesResult(true, true, 'WAPIAAAAW1', Carbon::parse('2026-05-04 09:00:00'));
    Order::$invoiceDataUsing = fn (Order $order) => new InvoiceData(
        new CustomerData('C'.$order->id, 'ES12345678Z', 'Acme SL', null, null, null, 'ES'),
        [new InvoiceLine('Consulting', 1, '100', 0, 'M16')],
        vies: $vies,
    );

    $document = $request(Order::create(['customer_vat' => 'ES12345678Z']))->refresh();

    expect($document->vies)->toBe($vies->toArray());
})->with([
    'invoice' => [fn (Order $order) => Moloni::invoice($order)],
    'pro-forma' => [fn (Order $order) => Moloni::proforma($order, 'quote-1')],
]);

it('stores no VIES evidence when there was no check', function () {
    Queue::fake();

    expect(Moloni::invoice(Order::create(['customer_vat' => '509999999']))->refresh()->vies)->toBeNull();
});

it('explains a refused conversion in Portuguese', function () {
    Queue::fake();
    app()->setLocale('pt');
    $proforma = Moloni::proforma(Order::create(['customer_vat' => '509999999']), 'quote-1');

    expect(catchMoloniException(fn () => Moloni::convert($proforma))->getMessage())
        ->toBe('Só é possível converter uma fatura pró-forma emitida.');
});
