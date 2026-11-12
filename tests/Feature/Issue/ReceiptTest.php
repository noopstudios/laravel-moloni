<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Testing\Fakes\QueueFake;
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
use Noop\Moloni\Tests\Fixtures\Order;

use function Pest\Laravel\travelTo;

beforeEach(function () {
    Http::preventStrayRequests();
    useIssueSettings(['document_sets' => ['invoice' => 41, 'invoice_receipt' => 42, 'receipt' => 43]]);
    travelTo(Carbon::parse('2026-05-04 10:00:00'));
    Event::fake([DocumentIssued::class, DocumentFailed::class]);
});

/**
 * An issued invoice of customer 301 (VAT 509999999) in company 5, Moloni id $documentId.
 *
 * @param  array<string, mixed>  $attributes
 */
function rpInvoice(int $documentId, int $cents, array $attributes = []): InvoiceDocument
{
    return InvoiceDocument::factory()->invoice()->issued()->create([
        'company_id' => 5,
        'document_set_id' => 41,
        'document_id' => $documentId,
        'document_number' => 'GR/'.$documentId,
        'reference' => '#INV-'.$documentId,
        'expected_total_cents' => $cents,
        'customer_id' => 301,
        'customer_vat' => '509999999',
        'customer_number' => 'GR26-C1',
        ...$attributes,
    ]);
}

function rpPayment(int $cents, string $method = 'transfer'): PaymentData
{
    return new PaymentData($method, Carbon::parse('2026-05-03 18:00:00'), $cents);
}

/**
 * Requests a receipt with the queue faked, so it stays pending.
 *
 * @param  list<array{0: InvoiceDocument, 1: int}>  $allocations
 */
function rpPending(array $allocations, ?string $key = null): InvoiceDocument
{
    if (! Queue::getFacadeRoot() instanceof QueueFake) {
        Queue::fake();
    }

    return Moloni::receipt(Order::create(), $allocations, rpPayment(array_sum(array_column($allocations, 1))), $key);
}

/**
 * A receipt among an invoice's reverse_associated_documents, as documents/getOne returns it.
 *
 * @param  array<string, mixed>  $nested
 * @return array<string, mixed>
 */
function rpReverse(int $receiptId, float $value, float $net, array $nested = []): array
{
    return [
        'document_id' => $receiptId,
        'value' => $value,
        'reverse_associated_document' => [
            'document_id' => $receiptId,
            'document_type_id' => 2,
            'document_set_id' => 43,
            'number' => 3,
            'status' => 1,
            'net_value' => $net,
            ...$nested,
        ],
    ];
}

/**
 * Moloni for receipts: receipts/insert answers 9001; documents/getOne answers the invoices (with
 * the reverse documents given per Moloni invoice id) and any receipt (RC/3, total $receiptTotal).
 *
 * @param  array<int, list<array<string, mixed>>>  $reverse
 * @param  array<string, mixed>  $overrides
 */
function rpFakeMoloni(float $receiptTotal, array $reverse = [], array $overrides = []): void
{
    Http::fake([
        '*/grant/*' => moloniGrant(),
        '*/receipts/insert/*' => ['valid' => 1, 'document_id' => 9001],
        '*/documents/getOne/*' => function (Request $request) use ($receiptTotal, $reverse) {
            $id = (int) $request['document_id'];

            return Http::response($id >= 9000
                ? moloniDocumentRow(['document_id' => $id, 'document_type_id' => 2, 'document_set_id' => 43, 'document_set_name' => 'RC', 'number' => 3, 'our_reference' => '', 'gross_value' => 0, 'net_value' => $receiptTotal])
                : moloniDocumentRow(['document_id' => $id, 'document_type_id' => 1, 'document_set_id' => 41, 'our_reference' => '#INV-'.$id, 'reverse_associated_documents' => $reverse[$id] ?? []]));
        },
        ...$overrides,
    ]);
}

it('issues one receipt for three invoices with a value per invoice', function () {
    rpFakeMoloni(163);
    $a = rpInvoice(8001, 12300);
    $b = rpInvoice(8002, 5000);
    $c = rpInvoice(8003, 2000);
    $order = Order::create();

    $receipt = Moloni::receipt($order, [[$a, 12300], [$b, 2000], [$c, 2000]], rpPayment(16300), 'payment-77')->fresh();

    $inserts = moloniRequests('receipts/insert');
    expect($inserts)->toHaveCount(1)
        ->and($inserts[0]->data())->toBe([
            'company_id' => 5,
            'date' => '2026-05-04',
            'document_set_id' => 43,
            'customer_id' => 301,
            'net_value' => 163.0,
            'associated_documents' => [
                ['associated_id' => 8001, 'value' => 123.0],
                ['associated_id' => 8002, 'value' => 20.0],
                ['associated_id' => 8003, 'value' => 20.0],
            ],
            'payments' => [['payment_method_id' => 22, 'date' => '2026-05-03', 'value' => 163.0]],
            'status' => 1,
        ])
        ->and($receipt?->type)->toBe(DocumentType::Receipt)
        ->and($receipt?->status)->toBe(DocumentStatus::Issued)
        ->and($receipt?->document_id)->toBe(9001)
        ->and($receipt?->document_number)->toBe('RC/3')
        ->and($receipt?->error_type)->toBeNull()
        ->and($receipt?->active_key)->toBeNull()
        ->and($receipt?->invoiceable?->is($order))->toBeTrue()
        ->and($receipt?->operation_key)->toBe(InvoiceDocument::operationKey(DocumentType::Receipt, $order, 'payment-77'))
        ->and($receipt?->customer_id)->toBe(301)
        ->and($receipt?->customer_vat)->toBe('509999999')
        ->and($receipt?->customer_number)->toBe('GR26-C1')
        ->and($receipt?->document_set_id)->toBe(43)
        ->and($receipt?->expected_total_cents)->toBe(16300)
        ->and($receipt?->links()->orderBy('associated_document_id')->get()->map(fn (InvoiceDocumentLink $link) => [$link->associated_document_id, $link->amount_cents])->all())
        ->toBe([[$a->id, 12300], [$b->id, 2000], [$c->id, 2000]])
        ->and([$a->outstandingCents(), $b->outstandingCents(), $c->outstandingCents()])->toBe([0, 3000, 0]);
    Event::assertDispatched(DocumentIssued::class, fn (DocumentIssued $event) => $event->document->is($receipt));
});

it('records the receipt pending with its frozen request before any HTTP call', function () {
    Http::fake();
    $a = rpInvoice(8001, 12300);

    $receipt = rpPending([[$a, 12300]]);

    Http::assertNothingSent();
    expect($receipt->status)->toBe(DocumentStatus::Pending)
        ->and($receipt->reference)->toMatch('/^#RC-[0-9A-F]{12}$/')
        ->and(strlen($receipt->reference))->toBeLessThanOrEqual(30)
        ->and($receipt->due_at)->toBeNull()
        ->and($receipt->payload)->toBe([
            'payment' => rpPayment(12300)->toArray(),
            'allocations' => [['invoice_document_id' => $a->id, 'moloni_document_id' => 8001, 'amount_cents' => 12300]],
        ]);
    Queue::assertPushed(IssueDocument::class, fn (IssueDocument $job) => $job->document->is($receipt) && $job->afterCommit === true);
});

it('rejects an amount above what an invoice still owes before calling Moloni', function () {
    Http::fake();
    $a = rpInvoice(8001, 12300);
    $settled = InvoiceDocument::factory()->receipt()->issued()->create(['company_id' => 5]);
    InvoiceDocumentLink::create(['document_id' => $settled->id, 'associated_document_id' => $a->id, 'amount_cents' => 10000]);
    Queue::fake();

    expect(fn () => Moloni::receipt(Order::create(), [[$a, 3000]], rpPayment(3000)))
        ->toThrow(InvalidArgumentException::class, 'still owes');

    Http::assertNothingSent();
    Queue::assertNothingPushed();
    expect(InvoiceDocument::where('type', DocumentType::Receipt)->count())->toBe(1)
        ->and(InvoiceDocumentLink::count())->toBe(1);
});

it('rejects the second of two concurrent receipts that would exceed the outstanding amount', function () {
    Http::fake();
    $a = rpInvoice(8001, 12300);
    $b = rpInvoice(8002, 5000);
    $first = rpPending([[$a, 10000], [$b, 5000]]);

    // The second request runs once the first has committed its pending receipt.
    expect(fn () => Moloni::receipt(Order::create(), [[$b, 1000]], rpPayment(1000)))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => Moloni::receipt(Order::create(), [[$a, 2400]], rpPayment(2400)))
        ->toThrow(InvalidArgumentException::class);

    Http::assertNothingSent();
    Queue::assertPushed(IssueDocument::class, 1);
    expect(InvoiceDocument::where('type', DocumentType::Receipt)->sole()->is($first))->toBeTrue()
        ->and(InvoiceDocumentLink::count())->toBe(2)
        ->and($a->outstandingCents())->toBe(2300);
});

it('rejects invoices from different Moloni customers', function () {
    Queue::fake();
    $a = rpInvoice(8001, 12300);
    $b = rpInvoice(8002, 5000, ['customer_id' => 302]);

    expect(fn () => Moloni::receipt(Order::create(), [[$a, 12300], [$b, 5000]], rpPayment(17300)))
        ->toThrow(InvalidArgumentException::class, 'same Moloni customer')
        ->and(InvoiceDocument::where('type', DocumentType::Receipt)->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('rejects invoices without a Moloni customer', function () {
    Queue::fake();
    $a = rpInvoice(8001, 12300, ['customer_id' => null]);

    expect(fn () => Moloni::receipt(Order::create(), [[$a, 12300]], rpPayment(12300)))
        ->toThrow(InvalidArgumentException::class, 'same Moloni customer');
});

it('rejects invoice-receipts and pro-formas as targets', function (Closure $target) {
    Queue::fake();
    $invoice = $target();

    expect(fn () => Moloni::receipt(Order::create(), [[$invoice, 1000]], rpPayment(1000)))
        ->toThrow(InvalidArgumentException::class)
        ->and(InvoiceDocument::where('type', DocumentType::Receipt)->count())->toBe(0)
        ->and(InvoiceDocumentLink::count())->toBe(0);
    Queue::assertNothingPushed();
})->with([
    'invoice-receipt' => [fn () => rpInvoice(8001, 12300, ['type' => DocumentType::InvoiceReceipt])],
    'pro forma' => [fn () => rpInvoice(8001, 12300, ['type' => DocumentType::Proforma])],
    'pending invoice' => [fn () => rpInvoice(8001, 12300, ['status' => DocumentStatus::Pending, 'document_id' => null])],
    'unknown invoice' => [fn () => rpInvoice(8001, 12300, ['status' => DocumentStatus::Unknown])],
    'issued without a Moloni id' => [fn () => rpInvoice(8001, 12300, ['document_id' => null])],
    'another company' => [fn () => rpInvoice(8001, 12300, ['company_id' => 6])],
    'another currency' => [fn () => rpInvoice(8001, 12300, ['currency' => 'USD'])],
]);

it('judges the invoice as stored, not the copy it was given', function () {
    Queue::fake();
    $a = rpInvoice(8001, 12300);
    $stale = InvoiceDocument::findOrFail($a->id);
    $a->status = DocumentStatus::Voided;
    $a->save();

    expect(fn () => Moloni::receipt(Order::create(), [[$stale, 12300]], rpPayment(12300)))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects allocations that do not add up to the payment amount', function () {
    Queue::fake();
    $a = rpInvoice(8001, 12300);
    $b = rpInvoice(8002, 5000);

    expect(fn () => Moloni::receipt(Order::create(), [[$a, 12300], [$b, 4000]], rpPayment(17300)))
        ->toThrow(InvalidArgumentException::class, 'add up to')
        ->and(InvoiceDocument::where('type', DocumentType::Receipt)->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('rejects malformed allocations', function (Closure $allocations) {
    Queue::fake();

    expect(fn () => Moloni::receipt(Order::create(), $allocations(rpInvoice(8001, 12300)), rpPayment(1000)))
        ->toThrow(InvalidArgumentException::class)
        ->and(InvoiceDocument::where('type', DocumentType::Receipt)->count())->toBe(0);
})->with([
    'none' => [fn (InvoiceDocument $a) => []],
    'zero amount' => [fn (InvoiceDocument $a) => [[$a, 0], [rpInvoice(8002, 5000), 1000]]],
    'negative amount' => [fn (InvoiceDocument $a) => [[$a, -1000], [rpInvoice(8002, 5000), 2000]]],
    'amount not in cents' => [fn (InvoiceDocument $a) => [[$a, 10.0]]],
    'not an invoice document' => [fn (InvoiceDocument $a) => [[Order::create(), 1000]]],
    'unsaved invoice' => [fn (InvoiceDocument $a) => [[new InvoiceDocument, 1000]]],
    'keyed allocation' => [fn (InvoiceDocument $a) => [['invoice' => $a, 'amount' => 1000]]],
]);

it('rejects an invoice that appears twice in the allocations', function () {
    Queue::fake();
    $a = rpInvoice(8001, 12300);

    expect(fn () => Moloni::receipt(Order::create(), [[$a, 500], [InvoiceDocument::findOrFail($a->id), 500]], rpPayment(1000)))
        ->toThrow(InvalidArgumentException::class, 'more than once')
        ->and(InvoiceDocument::where('type', DocumentType::Receipt)->count())->toBe(0);
});

it('settles one invoice of a final consumer but never combines them', function () {
    Queue::fake();
    $a = rpInvoice(8001, 12300, ['customer_vat' => null]);
    $b = rpInvoice(8002, 5000, ['customer_vat' => null]);

    expect(fn () => Moloni::receipt(Order::create(), [[$a, 12300], [$b, 5000]], rpPayment(17300)))
        ->toThrow(InvalidArgumentException::class, 'final consumer');

    $receipt = Moloni::receipt(Order::create(), [[$a, 12300]], rpPayment(12300));

    expect($receipt->status)->toBe(DocumentStatus::Pending)
        ->and($receipt->customer_vat)->toBeNull()
        ->and($receipt->customer_id)->toBe(301);
});

it('refuses a receipt the settings cannot issue, without creating a row', function (array $settings, PaymentData $payment, string $message) {
    Http::fake();
    Queue::fake();
    $a = rpInvoice(8001, 12300);
    useMoloniSettings($settings);

    $e = catchMoloniException(fn () => Moloni::receipt(Order::create(), [[$a, 1000]], $payment));

    expect($e->type)->toBe(FailureType::Validation)
        ->and($e->getMessage())->toContain($message)
        ->and(InvoiceDocument::where('type', DocumentType::Receipt)->count())->toBe(0);
    Http::assertNothingSent();
})->with([
    'no receipt document set' => [['document_sets' => ['invoice' => 41]], rpPayment(1000), 'No document set'],
    'unknown payment method' => [[], rpPayment(1000, 'cash'), 'payment method'],
    'no company' => [['company_id' => null], rpPayment(1000), 'No Moloni company'],
]);

it('refuses an unsaved owner', function () {
    Queue::fake();

    expect(fn () => Moloni::receipt(new Order, [[rpInvoice(8001, 12300), 1000]], rpPayment(1000)))
        ->toThrow(InvalidArgumentException::class);
});

it('reduces outstanding while the receipt is still pending', function () {
    Http::fake();
    $a = rpInvoice(8001, 12300);
    $b = rpInvoice(8002, 5000);

    $receipt = rpPending([[$a, 12300], [$b, 1000]]);

    expect($receipt->status)->toBe(DocumentStatus::Pending)
        ->and($a->outstandingCents())->toBe(0)
        ->and($b->outstandingCents())->toBe(4000)
        ->and(InvoiceDocument::query()->openForVat('509999999')->pluck('id')->all())->toBe([$b->id]);
    Http::assertNothingSent();
});

it('keeps the links when Moloni fails so the receipt can be retried', function () {
    rpFakeMoloni(30, overrides: ['*/receipts/insert/*' => [[
        ['code' => '10 associated_id', 'description' => 'The associated document is not valid.'],
        ['code' => '2 value 0 null null 0 14.6', 'description' => "Field 'value' must be lesser or equal to 14.6"],
    ]]]);
    $a = rpInvoice(8001, 12300);
    $b = rpInvoice(8002, 5000);
    $receipt = rpPending([[$a, 1000], [$b, 2000]]);

    runIssueJob($receipt);

    $receipt->refresh();
    expect($receipt->status)->toBe(DocumentStatus::Failed)
        ->and($receipt->error_type)->toBe(FailureType::Validation->value)
        ->and($receipt->error_message)->toContain('14.6')
        ->and($receipt->links()->count())->toBe(2)
        // A failed receipt no longer counts against the invoices.
        ->and($a->outstandingCents())->toBe(12300)
        ->and($b->outstandingCents())->toBe(5000)
        ->and(moloniRequests('receipts/insert'))->toHaveCount(1);
    Event::assertDispatched(DocumentFailed::class);

    Moloni::retry($receipt);

    expect($receipt->status)->toBe(DocumentStatus::Pending)
        ->and($receipt->links()->count())->toBe(2)
        ->and($a->outstandingCents())->toBe(11300);
});

it('retries a failed receipt only while its allocations still fit what the invoices owe', function () {
    Http::fake();
    $a = rpInvoice(8001, 12300);
    $failed = rpPending([[$a, 10000]]);
    $failed->fill(['status' => DocumentStatus::Failed, 'error_type' => 'validation'])->save();
    rpPending([[$a, 10000]]);

    $e = catchMoloniException(fn () => Moloni::retry($failed));

    expect($e->type)->toBe(FailureType::Validation)
        ->and($e->getMessage())->toContain('still owe')
        ->and($failed->fresh()?->status)->toBe(DocumentStatus::Failed)
        ->and($failed->fresh()?->error_type)->toBe('validation')
        ->and($a->outstandingCents())->toBe(2300);
    Queue::assertPushed(IssueDocument::class, 2);
    Http::assertNothingSent();
});

it('never reissues a receipt', function () {
    Queue::fake();
    $receipt = rpPending([[rpInvoice(8001, 12300), 12300]]);
    $receipt->fill(['status' => DocumentStatus::Unknown])->save();

    expect(catchMoloniException(fn () => Moloni::reissue($receipt))->type)->toBe(FailureType::Validation)
        ->and($receipt->fresh()?->status)->toBe(DocumentStatus::Unknown)
        ->and(InvoiceDocument::where('type', DocumentType::Receipt)->count())->toBe(1);
});

it('returns the same receipt for the same idempotency key and refuses other allocations with it', function () {
    Http::fake();
    $a = rpInvoice(8001, 12300);
    $b = rpInvoice(8002, 5000);
    $order = Order::create();
    Queue::fake();

    $first = Moloni::receipt($order, [[$a, 1000], [$b, 2000]], rpPayment(3000), 'payment-1');
    // Same allocations in another order: the same request.
    $again = Moloni::receipt($order, [[$b, 2000], [$a, 1000]], rpPayment(3000), 'payment-1');

    expect($again->is($first))->toBeTrue()
        ->and(InvoiceDocument::where('type', DocumentType::Receipt)->count())->toBe(1)
        ->and(InvoiceDocumentLink::count())->toBe(2);
    Queue::assertPushed(IssueDocument::class, 1);

    expect(fn () => Moloni::receipt($order, [[$a, 1500], [$b, 1500]], rpPayment(3000), 'payment-1'))
        ->toThrow(InvalidArgumentException::class, 'payment-1')
        ->and(fn () => Moloni::receipt($order, [[$a, 1000]], rpPayment(1000), 'payment-1'))
        ->toThrow(InvalidArgumentException::class, 'payment-1');

    // Without a key, every call is a new receipt.
    Moloni::receipt($order, [[$a, 1000]], rpPayment(1000));
    Moloni::receipt($order, [[$a, 1000]], rpPayment(1000));

    expect(InvoiceDocument::where('type', DocumentType::Receipt)->count())->toBe(3)
        ->and($a->outstandingCents())->toBe(9300);
    Http::assertNothingSent();
});

it('returns the receipt a concurrent call with the same key committed while this one waited for the invoices', function () {
    Http::fake();
    Queue::fake();
    $a = rpInvoice(8001, 12300);
    $order = Order::create();
    $concurrent = null;
    $fired = false;
    // Fires when the call reads the invoices under lock, after its own lookup by key found nothing:
    // the other call (here a savepoint inside the first) records the same receipt first.
    InvoiceDocument::retrieved(function (InvoiceDocument $document) use (&$concurrent, &$fired, $a, $order) {
        if ($fired || $document->id !== $a->id) {
            return;
        }

        $fired = true;
        $concurrent = Moloni::receipt($order, [[$a, 12300]], rpPayment(12300), 'payment-9');
    });

    $receipt = Moloni::receipt($order, [[$a, 12300]], rpPayment(12300), 'payment-9');

    expect($concurrent)->toBeInstanceOf(InvoiceDocument::class)
        ->and($receipt->is($concurrent))->toBeTrue()
        ->and(InvoiceDocument::where('type', DocumentType::Receipt)->count())->toBe(1)
        ->and(InvoiceDocumentLink::count())->toBe(1);
    Queue::assertPushed(IssueDocument::class, 1);
});

it('refuses an idempotency key that does not fit the operation key', function () {
    Queue::fake();

    expect(fn () => Moloni::receipt(Order::create(), [[rpInvoice(8001, 12300), 1000]], rpPayment(1000), str_repeat('k', 250)))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => Moloni::receipt(Order::create(), [[rpInvoice(8002, 12300), 1000]], rpPayment(1000), ''))
        ->toThrow(InvalidArgumentException::class);
});

it('explains a refused receipt in Portuguese', function () {
    Queue::fake();
    app()->setLocale('pt');
    $a = rpInvoice(8001, 12300);

    expect(fn () => Moloni::receipt(Order::create(), [[$a, 12400]], rpPayment(12400)))
        ->toThrow(InvalidArgumentException::class, 'O valor de 124.00 para GR/8001 excede os 123.00 que a fatura ainda deve.');
});

it('adopts a receipt that already landed in Moloni instead of inserting again', function (DocumentStatus $status) {
    rpFakeMoloni(30, [
        8001 => [rpReverse(9005, 10, 30)],
        8002 => [rpReverse(9005, 20.0, 30), ['document_id' => 9100, 'value' => 0, 'reverse_associated_document' => ['document_id' => 9100, 'document_type_id' => 3, 'document_set_id' => 44, 'status' => 1, 'net_value' => 5]]],
    ]);
    $a = rpInvoice(8001, 12300);
    $b = rpInvoice(8002, 5000);
    $receipt = rpPending([[$a, 1000], [$b, 2000]]);
    $receipt->fill(['status' => $status, 'attempts' => 1])->save();

    runIssueJob($receipt);

    $receipt->refresh();
    expect($receipt->status)->toBe(DocumentStatus::Issued)
        ->and($receipt->document_id)->toBe(9005)
        ->and($receipt->document_number)->toBe('RC/3')
        ->and($receipt->error_type)->toBeNull()
        ->and(moloniRequests('receipts/insert'))->toHaveCount(0);
    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/documents/getOne/') && $request['document_id'] === 8001);
    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/documents/getOne/') && $request['document_id'] === 8002);
    Event::assertDispatched(DocumentIssued::class);
})->with([DocumentStatus::Pending, DocumentStatus::Issuing, DocumentStatus::Unknown]);

it('blocks the insert when a receipt in Moloni cannot be confirmed as this one', function (array $reverse) {
    rpFakeMoloni(30, $reverse);
    $a = rpInvoice(8001, 12300);
    $b = rpInvoice(8002, 5000);
    $receipt = rpPending([[$a, 1000], [$b, 2000]]);
    $receipt->fill(['status' => DocumentStatus::Unknown, 'attempts' => 1])->save();

    runIssueJob($receipt);

    $receipt->refresh();
    expect($receipt->status)->toBe(DocumentStatus::Unknown)
        ->and($receipt->error_type)->toBe('ambiguous_reconciliation')
        ->and($receipt->document_id)->toBeNull()
        ->and(moloniRequests('receipts/insert'))->toHaveCount(0);
    Event::assertDispatched(DocumentFailed::class);
    Event::assertNotDispatched(DocumentIssued::class);
})->with([
    'on one invoice only' => [[8001 => [rpReverse(9005, 10, 30)]]],
    'another value on one invoice' => [[8001 => [rpReverse(9005, 10, 30)], 8002 => [rpReverse(9005, 19.99, 30)]]],
    'another total' => [[8001 => [rpReverse(9005, 10, 31)], 8002 => [rpReverse(9005, 20, 31)]]],
    'two receipts' => [[8001 => [rpReverse(9005, 10, 30)], 8002 => [rpReverse(9006, 20, 30)]]],
    'two receipts on every invoice' => [[8001 => [rpReverse(9005, 10, 30), rpReverse(9006, 10, 30)], 8002 => [rpReverse(9005, 20, 30), rpReverse(9006, 20, 30)]]],
    'an unreadable type' => [[8001 => [rpReverse(9005, 10, 30, ['document_type_id' => null])]]],
    'an unreadable id' => [[8001 => [['value' => 10, 'reverse_associated_document' => ['document_type_id' => 2, 'document_set_id' => 43, 'status' => 1, 'net_value' => 30]]]]],
    'disagreeing ids' => [[8001 => [rpReverse(9005, 10, 30, ['document_id' => 9006])], 8002 => [rpReverse(9005, 20, 30, ['document_id' => 9006])]]],
]);

it('issues when no receipt in Moloni could be this one', function (int $receiptId, array $nested) {
    rpFakeMoloni(30, [
        8001 => [rpReverse($receiptId, 10, 30, $nested)],
        8002 => [rpReverse($receiptId, 20, 30, $nested)],
    ]);
    InvoiceDocument::factory()->receipt()->issued()->create(['company_id' => 5, 'document_id' => 9100]);
    $a = rpInvoice(8001, 12300);
    $b = rpInvoice(8002, 5000);
    $receipt = rpPending([[$a, 1000], [$b, 2000]]);

    runIssueJob($receipt);

    expect($receipt->fresh()?->document_id)->toBe(9001)
        ->and($receipt->fresh()?->status)->toBe(DocumentStatus::Issued)
        ->and(moloniRequests('receipts/insert'))->toHaveCount(1);
})->with([
    'recorded on another row' => [9100, []],
    'cancelled' => [9101, ['status' => 2]],
    'another series' => [9101, ['document_set_id' => 44]],
    'a credit note' => [9101, ['document_type_id' => 3]],
]);

it('fails a pending receipt when Moloni answers with another invoice', function () {
    rpFakeMoloni(30, overrides: ['*/documents/getOne/*' => moloniDocumentRow(['document_id' => 1])]);
    $receipt = rpPending([[rpInvoice(8001, 12300), 1000]]);

    runIssueJob($receipt);

    expect($receipt->fresh()?->status)->toBe(DocumentStatus::Failed)
        ->and($receipt->fresh()?->error_type)->toBe(FailureType::Validation->value)
        ->and(moloniRequests('receipts/insert'))->toHaveCount(0);
});

it('keeps an uncertain receipt and retries later when an invoice cannot be read', function () {
    rpFakeMoloni(30, overrides: ['*/documents/getOne/*' => Http::response('Unavailable', 503)]);
    $receipt = rpPending([[rpInvoice(8001, 12300), 1000]]);
    $receipt->fill(['status' => DocumentStatus::Unknown, 'attempts' => 1])->save();

    $e = catchMoloniException(fn () => runIssueJob($receipt));

    expect($e->type)->toBe(FailureType::Transient)
        ->and($receipt->fresh()?->status)->toBe(DocumentStatus::Unknown)
        ->and(moloniRequests('receipts/insert'))->toHaveCount(0);
});

it('flags a receipt whose total in Moloni differs', function () {
    rpFakeMoloni(30.01);

    $receipt = Moloni::receipt(Order::create(), [[rpInvoice(8001, 12300), 3000]], rpPayment(3000))->fresh();

    expect($receipt?->status)->toBe(DocumentStatus::Issued)
        ->and($receipt?->error_type)->toBe('total_mismatch');
});
