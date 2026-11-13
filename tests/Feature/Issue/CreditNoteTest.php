<?php

use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Noop\Moloni\Data\CreditLine;
use Noop\Moloni\Data\CustomerData;
use Noop\Moloni\Data\InvoiceData;
use Noop\Moloni\Data\InvoiceLine;
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
    useIssueSettings(['document_sets' => ['invoice' => 41, 'invoice_receipt' => 42, 'receipt' => 43, 'credit_note' => 44]]);
    travelTo(Carbon::parse('2026-05-04 10:00:00'));
    Event::fake([DocumentIssued::class, DocumentFailed::class]);
});

/**
 * The frozen request of the origin: 3 × "Consulting" at 10.00 and 2 × "Support" at 5.00, both at
 * 23% (36.90 + 12.30 = 49.20).
 *
 * @return array<string, mixed>
 */
function cnFrozenInvoice(): array
{
    return (new InvoiceData(
        new CustomerData('C1', '509999999', 'Acme Lda', null, null, null, null),
        [new InvoiceLine('Consulting', 3, '10', 23), new InvoiceLine('Support', 2, '5', 23)],
    ))->toArray();
}

/**
 * An issued origin (Moloni id 8001, total 49.20) of customer 301 for a new order.
 *
 * @param  array<string, mixed>  $attributes
 */
function cnOrigin(DocumentType $type = DocumentType::Invoice, array $attributes = []): InvoiceDocument
{
    $order = Order::create(['customer_vat' => '509999999']);

    return InvoiceDocument::factory()->issued()->create([
        'type' => $type,
        'invoiceable_type' => $order->getMorphClass(),
        'invoiceable_id' => $order->id,
        'company_id' => 5,
        'document_set_id' => $type === DocumentType::Invoice ? 41 : 42,
        'document_id' => 8001,
        'document_number' => 'GR/8',
        'reference' => '#ORDER-1',
        'expected_total_cents' => 4920,
        'customer_id' => 301,
        'customer_vat' => '509999999',
        'customer_number' => 'GR26-C1',
        'payload' => ['invoice' => cnFrozenInvoice(), 'payment' => null, 'due_in_days' => 0],
        ...$attributes,
    ]);
}

/**
 * The origin as documents/getOne returns it, with the lines Moloni stored.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function cnOriginRow(int $id = 8001, array $overrides = []): array
{
    $tax = ['tax_id' => 82, 'value' => 23, 'order' => 1, 'cumulative' => 0, 'incidence_value' => 30, 'total_value' => 6.9];

    return moloniDocumentRow([
        'document_id' => $id,
        'document_type_id' => 1,
        'document_set_id' => 41,
        'our_reference' => '#ORDER-1',
        'gross_value' => 40,
        'net_value' => 49.2,
        'products' => [
            ['document_product_id' => 50001, 'product_id' => 900, 'name' => 'Consulting', 'qty' => 3, 'price' => 10, 'exemption_reason' => '', 'taxes' => [$tax]],
            ['document_product_id' => 50002, 'product_id' => 900, 'name' => 'Support', 'qty' => 2, 'price' => 5, 'exemption_reason' => '', 'taxes' => [$tax]],
        ],
        ...$overrides,
    ]);
}

/**
 * Moloni for credit notes: creditNotes/insert answers 9501, 9502, …; documents/getOne answers the
 * origin and, for ids from 9500, a credit note (NC/1) with $creditTotal; documents/getAll has nothing.
 *
 * @param  array<string, mixed>  $overrides
 * @param  array<string, mixed>  $originOverrides
 */
function cnFake(float $creditTotal, array $overrides = [], array $originOverrides = []): void
{
    $nextId = 9501;

    Http::fake([
        '*/grant/*' => moloniGrant(),
        '*/creditNotes/insert/*' => function () use (&$nextId) {
            return Http::response(['valid' => 1, 'document_id' => $nextId++]);
        },
        '*/documents/getAll/*' => Http::response([]),
        '*/documents/getOne/*' => function (Request $request) use ($creditTotal, $originOverrides) {
            $id = (int) $request['document_id'];

            return Http::response($id >= 9500
                ? moloniDocumentRow(['document_id' => $id, 'document_type_id' => 3, 'document_set_id' => 44, 'document_set_name' => 'NC', 'number' => 1, 'our_reference' => '', 'net_value' => $creditTotal])
                : cnOriginRow($id, $originOverrides));
        },
        ...$overrides,
    ]);
}

/**
 * Requests a credit note with the queue faked, so it stays pending.
 *
 * @param  list<CreditLine>|null  $lines
 */
function cnPending(InvoiceDocument $origin, ?array $lines = null, ?string $key = null): InvoiceDocument
{
    if (! Queue::getFacadeRoot() instanceof QueueFake) {
        Queue::fake();
    }

    return Moloni::creditNote($origin, $lines, $key);
}

/**
 * @return array<string, mixed>
 */
function cnTaxes(): array
{
    return ['taxes' => [['tax_id' => 82, 'value' => 23.0, 'order' => 1, 'cumulative' => 0]]];
}

it('issues a full credit note referencing every line of the invoice', function () {
    cnFake(49.2);
    $origin = cnOrigin();

    $credit = Moloni::creditNote($origin)->fresh();

    $inserts = moloniRequests('creditNotes/insert');
    expect($inserts)->toHaveCount(1)
        ->and($inserts[0]->data())->toBe([
            'company_id' => 5,
            'date' => '2026-05-04',
            'document_set_id' => 44,
            'customer_id' => 301,
            'our_reference' => '#ORDER-1/NC1',
            'status' => 1,
            'products' => [
                ['product_id' => 900, 'name' => 'Consulting', 'qty' => 3.0, 'price' => 10.0, 'related_id' => 50001, ...cnTaxes()],
                ['product_id' => 900, 'name' => 'Support', 'qty' => 2.0, 'price' => 5.0, 'related_id' => 50002, ...cnTaxes()],
            ],
            'associated_documents' => [['associated_id' => 8001, 'value' => 49.2]],
        ])
        ->and($credit?->type)->toBe(DocumentType::CreditNote)
        ->and($credit?->status)->toBe(DocumentStatus::Issued)
        ->and($credit?->document_id)->toBe(9501)
        ->and($credit?->document_number)->toBe('NC/1')
        ->and($credit?->error_type)->toBeNull()
        ->and($credit?->reference)->toBe('#ORDER-1/NC1')
        ->and($credit?->document_set_id)->toBe(44)
        ->and($credit?->expected_total_cents)->toBe(4920)
        ->and($credit?->customer_id)->toBe(301)
        ->and($credit?->customer_vat)->toBe('509999999')
        ->and($credit?->customer_number)->toBe('GR26-C1')
        ->and($credit?->active_key)->toBeNull()
        ->and($credit?->invoiceable?->is($origin->invoiceable))->toBeTrue()
        // Stored as JSON: whole quantities come back as integers.
        ->and($credit?->payload['credit'] ?? null)->toEqual([
            'origin_document_id' => $origin->id,
            'moloni_document_id' => 8001,
            'lines' => [['index' => 0, 'quantity' => 3.0], ['index' => 1, 'quantity' => 2.0]],
            'all' => true,
            'applied_cents' => 4920,
        ])
        ->and($credit?->links()->get()->map(fn (InvoiceDocumentLink $link) => [$link->associated_document_id, $link->amount_cents])->all())
        ->toBe([[$origin->id, 4920]])
        ->and($origin->outstandingCents())->toBe(0)
        // The origin itself stays issued: a credit note does not change it.
        ->and($origin->fresh()?->status)->toBe(DocumentStatus::Issued);
    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/documents/getOne/') && $request['document_id'] === 8001);
    Event::assertDispatched(DocumentIssued::class, fn (DocumentIssued $event) => $event->document->is($credit));
});

it('issues a partial credit note for one line and a smaller quantity', function () {
    cnFake(6.15);
    $origin = cnOrigin();

    $credit = Moloni::creditNote($origin, [new CreditLine(1, 1)])->fresh();

    expect(moloniRequests('creditNotes/insert')[0]->data())->toMatchArray([
        'products' => [['product_id' => 900, 'name' => 'Support', 'qty' => 1.0, 'price' => 5.0, 'related_id' => 50002, ...cnTaxes()]],
        'associated_documents' => [['associated_id' => 8001, 'value' => 6.15]],
    ])
        ->and($credit?->status)->toBe(DocumentStatus::Issued)
        ->and($credit?->error_type)->toBeNull()
        ->and($credit?->expected_total_cents)->toBe(615)
        ->and(InvoiceData::fromArray($credit->payload['invoice'] ?? [])->lines)->toEqual([new InvoiceLine('Support', 1, '5', 23)]);
});

it('rejects a credit above what the document still has available', function (Closure $lines, string $message) {
    Http::fake();
    $origin = cnOrigin();
    cnPending($origin, [new CreditLine(0, 2)]);

    expect(fn () => Moloni::creditNote($origin, $lines()))->toThrow(InvalidArgumentException::class, $message);

    Http::assertNothingSent();
    Queue::assertPushed(IssueDocument::class, 1);
    expect(InvoiceDocument::where('type', DocumentType::CreditNote)->count())->toBe(1);
})->with([
    'more than what is left on the line' => [fn () => [new CreditLine(0, 1.5)], 'at most 1'],
    'a line the document does not have' => [fn () => [new CreditLine(2, 1)], 'no line 2'],
    'the same line twice' => [fn () => [new CreditLine(1, 1), new CreditLine(1, 1)], 'more than once'],
    'no lines' => [fn () => [], 'Nothing'],
    'not a credit line' => [fn () => [[1, 1]], 'CreditLine'],
]);

it('refuses a credit line without a positive quantity', function () {
    expect(fn () => new CreditLine(0, 0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new CreditLine(0, -1))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new CreditLine(-1, 1))->toThrow(InvalidArgumentException::class);
});

it('credits only what is left, and refuses when nothing is', function () {
    Http::fake();
    $origin = cnOrigin();
    cnPending($origin, [new CreditLine(0, 2)]);

    $rest = cnPending($origin);

    expect($rest->payload['credit']['lines'] ?? null)->toEqual([['index' => 0, 'quantity' => 1], ['index' => 1, 'quantity' => 2]])
        ->and($rest->reference)->toBe('#ORDER-1/NC2')
        ->and($rest->expected_total_cents)->toBe(1230 + 1230)
        ->and(fn () => cnPending($origin))->toThrow(InvalidArgumentException::class, 'Nothing')
        ->and(fn () => cnPending($origin, [new CreditLine(1, 0.5)]))->toThrow(InvalidArgumentException::class, 'at most 0');
});

it('frees what a failed or voided credit note took', function (DocumentStatus $status) {
    Http::fake();
    $origin = cnOrigin();
    $first = cnPending($origin, [new CreditLine(0, 3)]);
    $first->fill(['status' => $status])->save();

    $second = cnPending($origin, [new CreditLine(0, 3)]);

    expect($second->reference)->toBe('#ORDER-1/NC2')
        ->and($origin->outstandingCents())->toBe(4920 - 3690);
})->with([DocumentStatus::Failed, DocumentStatus::Voided]);

it('reduces the invoice outstanding by the credited amount', function () {
    Http::fake();
    $origin = cnOrigin();

    $credit = cnPending($origin, [new CreditLine(0, 1)]);

    expect($credit->status)->toBe(DocumentStatus::Pending)
        ->and($credit->expected_total_cents)->toBe(1230)
        ->and($origin->outstandingCents())->toBe(4920 - 1230)
        ->and(InvoiceDocumentLink::sole()->amount_cents)->toBe(1230);
    Http::assertNothingSent();
});

it('claims nothing of the balance of an invoice-receipt', function () {
    cnFake(12.3);
    $origin = cnOrigin(DocumentType::InvoiceReceipt);

    $credit = Moloni::creditNote($origin, [new CreditLine(0, 1)])->fresh();

    expect(moloniRequests('creditNotes/insert')[0]['associated_documents'])->toBe([['associated_id' => 8001, 'value' => 0.0]])
        ->and($credit?->status)->toBe(DocumentStatus::Issued)
        ->and($credit?->expected_total_cents)->toBe(1230)
        ->and($credit?->payload['credit']['applied_cents'] ?? null)->toBe(0)
        ->and(InvoiceDocumentLink::sole()->amount_cents)->toBe(0);
});

it('claims at most the open balance of a partly paid invoice', function () {
    cnFake(49.2);
    $origin = cnOrigin();
    $receipt = InvoiceDocument::factory()->receipt()->issued()->create(['company_id' => 5]);
    InvoiceDocumentLink::create(['document_id' => $receipt->id, 'associated_document_id' => $origin->id, 'amount_cents' => 4000]);

    $credit = Moloni::creditNote($origin)->fresh();

    expect(moloniRequests('creditNotes/insert')[0]['associated_documents'])->toBe([['associated_id' => 8001, 'value' => 9.2]])
        ->and($credit?->expected_total_cents)->toBe(4920)
        ->and($credit?->links()->sole()->amount_cents)->toBe(920)
        ->and($origin->outstandingCents())->toBe(0);
});

it('claims nothing of a fully paid invoice', function () {
    cnFake(12.3);
    $origin = cnOrigin();
    $receipt = InvoiceDocument::factory()->receipt()->issued()->create(['company_id' => 5]);
    InvoiceDocumentLink::create(['document_id' => $receipt->id, 'associated_document_id' => $origin->id, 'amount_cents' => 4920]);

    Moloni::creditNote($origin, [new CreditLine(0, 1)]);

    expect(moloniRequests('creditNotes/insert')[0]['associated_documents'])->toBe([['associated_id' => 8001, 'value' => 0.0]])
        ->and(InvoiceDocumentLink::where('associated_document_id', $origin->id)->sum('amount_cents'))->toBe(4920);
});

it('limits a second credit note by what the first one claimed', function () {
    Http::fake();
    $origin = cnOrigin();
    $receipt = InvoiceDocument::factory()->receipt()->issued()->create(['company_id' => 5]);
    InvoiceDocumentLink::create(['document_id' => $receipt->id, 'associated_document_id' => $origin->id, 'amount_cents' => 3000]);

    $first = cnPending($origin, [new CreditLine(0, 1)]);
    $second = cnPending($origin, [new CreditLine(0, 1)]);

    expect($first->links()->sole()->amount_cents)->toBe(1230)
        ->and($second->links()->sole()->amount_cents)->toBe(690)
        ->and($second->payload['credit']['applied_cents'] ?? null)->toBe(690)
        ->and($origin->outstandingCents())->toBe(0);
});

it('returns the same credit note for the same key and refuses other lines with it', function () {
    Http::fake();
    $origin = cnOrigin();

    $first = cnPending($origin, [new CreditLine(0, 1), new CreditLine(1, 2)], 'refund-1');
    $again = cnPending($origin, [new CreditLine(1, 2), new CreditLine(0, 1.0)], 'refund-1');
    $all = cnPending($origin, null, 'refund-2');

    expect($again->is($first))->toBeTrue()
        ->and(cnPending($origin, null, 'refund-2')->is($all))->toBeTrue()
        ->and(InvoiceDocument::where('type', DocumentType::CreditNote)->count())->toBe(2)
        ->and(InvoiceDocumentLink::count())->toBe(2)
        ->and($first->operation_key)->toBe(InvoiceDocument::operationKey(DocumentType::CreditNote, $origin, 'refund-1'))
        ->and(fn () => cnPending($origin, [new CreditLine(0, 1)], 'refund-1'))
        ->toThrow(InvalidArgumentException::class, 'The credit note key refund-1 already belongs to another credit note; use a new key.')
        ->and(fn () => cnPending($origin, [new CreditLine(0, 1), new CreditLine(1, 2)], 'refund-2'))
        ->toThrow(InvalidArgumentException::class, 'refund-2')
        ->and(fn () => cnPending($origin, null, ''))
        ->toThrow(InvalidArgumentException::class);
    Queue::assertPushed(IssueDocument::class, 2);
    Http::assertNothingSent();
});

it('sends a failed credit note again when it is requested again with its key', function () {
    Http::fake();
    $origin = cnOrigin();
    $credit = cnPending($origin, [new CreditLine(0, 1)], 'refund-3');
    $credit->fill(['status' => DocumentStatus::Failed, 'error_type' => 'validation', 'attempts' => 1])->save();
    (new UniqueLock(app(CacheRepository::class)))->release(new IssueDocument($credit));

    $again = cnPending($origin, [new CreditLine(0, 1)], 'refund-3');

    expect($again->is($credit))->toBeTrue()
        ->and($again->status)->toBe(DocumentStatus::Pending)
        ->and($again->attempts)->toBe(0);
    Queue::assertPushed(IssueDocument::class, 2);
});

it('retries a failed credit note only while its lines and claim still fit', function (Closure $meanwhile) {
    Http::fake();
    $origin = cnOrigin();
    $failed = cnPending($origin, [new CreditLine(0, 3)]);
    $failed->fill(['status' => DocumentStatus::Failed, 'error_type' => 'validation'])->save();
    $meanwhile($origin);

    $e = catchMoloniException(fn () => Moloni::retry($failed));

    expect($e->type)->toBe(FailureType::Validation)
        ->and($e->getMessage())->toContain('no longer fits')
        ->and($failed->fresh()?->status)->toBe(DocumentStatus::Failed);
    Http::assertNothingSent();
})->with([
    'another credit note took the line' => [fn (InvoiceDocument $origin) => cnPending($origin, [new CreditLine(0, 1)])],
    'a receipt took the balance' => [function (InvoiceDocument $origin) {
        $receipt = InvoiceDocument::factory()->receipt()->issued()->create(['company_id' => 5]);
        InvoiceDocumentLink::create(['document_id' => $receipt->id, 'associated_document_id' => $origin->id, 'amount_cents' => 4000]);
    }],
    'the origin was voided' => [fn (InvoiceDocument $origin) => $origin->fill(['status' => DocumentStatus::Voided])->save()],
]);

it('retries a failed credit note that still fits', function () {
    Http::fake();
    $origin = cnOrigin();
    $failed = cnPending($origin, [new CreditLine(0, 1)]);
    $failed->fill(['status' => DocumentStatus::Failed, 'error_type' => 'validation'])->save();
    (new UniqueLock(app(CacheRepository::class)))->release(new IssueDocument($failed));

    Moloni::retry($failed);

    expect($failed->status)->toBe(DocumentStatus::Pending)
        ->and($origin->outstandingCents())->toBe(4920 - 1230);
});

it('never reissues a credit note', function () {
    Http::fake();
    $credit = cnPending(cnOrigin(), [new CreditLine(0, 1)]);
    $credit->fill(['status' => DocumentStatus::Unknown])->save();

    expect(catchMoloniException(fn () => Moloni::reissue($credit))->type)->toBe(FailureType::Validation)
        ->and($credit->fresh()?->status)->toBe(DocumentStatus::Unknown);
});

it('refuses to credit what is not an issued invoice or invoice-receipt of this company', function (Closure $origin) {
    Http::fake();
    Queue::fake();

    expect(fn () => Moloni::creditNote($origin()))->toThrow(InvalidArgumentException::class)
        ->and(InvoiceDocument::count())->toBe(1)
        ->and(InvoiceDocumentLink::count())->toBe(0);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
})->with([
    'a pro-forma' => [fn () => cnOrigin(DocumentType::Proforma)],
    'a receipt' => [fn () => cnOrigin(DocumentType::Receipt)],
    'a credit note' => [fn () => cnOrigin(DocumentType::CreditNote)],
    'a pending invoice' => [fn () => cnOrigin(attributes: ['status' => DocumentStatus::Pending, 'document_id' => null])],
    'an unknown invoice' => [fn () => cnOrigin(attributes: ['status' => DocumentStatus::Unknown])],
    'a voided invoice' => [fn () => cnOrigin(attributes: ['status' => DocumentStatus::Voided])],
    'issued without a Moloni id' => [fn () => cnOrigin(attributes: ['document_id' => null])],
    'another company' => [fn () => cnOrigin(attributes: ['company_id' => 6])],
    'no frozen lines' => [fn () => cnOrigin(attributes: ['payload' => null])],
]);

it('judges the origin as stored, not the copy it was given', function () {
    Queue::fake();
    $origin = cnOrigin();
    $stale = InvoiceDocument::findOrFail($origin->id);
    $origin->fill(['status' => DocumentStatus::Voided])->save();

    expect(fn () => Moloni::creditNote($stale))->toThrow(InvalidArgumentException::class);
});

it('refuses a credit note the settings cannot issue', function (array $settings, string $message) {
    Http::fake();
    Queue::fake();
    $origin = cnOrigin();
    useMoloniSettings($settings);

    $e = catchMoloniException(fn () => Moloni::creditNote($origin));

    expect($e->type)->toBe(FailureType::Validation)
        ->and($e->getMessage())->toContain($message)
        ->and(InvoiceDocument::where('type', DocumentType::CreditNote)->count())->toBe(0);
})->with([
    'no credit note document set' => [['document_sets' => ['invoice' => 41]], 'No document set'],
    'no company' => [['company_id' => null], 'No Moloni company'],
]);

it('fails without inserting when the origin in Moloni does not match its lines', function (array $originOverrides) {
    cnFake(49.2, originOverrides: $originOverrides);
    $origin = cnOrigin();

    $credit = Moloni::creditNote($origin)->fresh();

    expect($credit?->status)->toBe(DocumentStatus::Failed)
        ->and($credit?->error_type)->toBe(FailureType::Validation->value)
        ->and($credit?->document_id)->toBeNull()
        ->and(moloniRequests('creditNotes/insert'))->toHaveCount(0)
        // A failed credit note no longer claims anything.
        ->and($origin->outstandingCents())->toBe(4920);
    Event::assertDispatched(DocumentFailed::class);
})->with([
    'another line count' => [['products' => [cnOriginRow()['products'][0]]]],
    'another quantity' => [['products' => [cnOriginRow()['products'][0], [...cnOriginRow()['products'][1], 'qty' => 1]]]],
    'another price' => [['products' => [cnOriginRow()['products'][0], [...cnOriginRow()['products'][1], 'price' => 4.5]]]],
    'another name' => [['products' => [cnOriginRow()['products'][0], [...cnOriginRow()['products'][1], 'name' => 'Other']]]],
    'a line without its id' => [['products' => [cnOriginRow()['products'][0], [...cnOriginRow()['products'][1], 'document_product_id' => null]]]],
    'another customer' => [['customer_id' => 302]],
    'another document' => [['document_id' => 8002]],
]);

it('checks the product of a line when the request named it', function () {
    cnFake(49.2, originOverrides: ['products' => [[...cnOriginRow()['products'][0], 'product_id' => 901], cnOriginRow()['products'][1]]]);
    $frozen = cnFrozenInvoice();
    $frozen['lines'][0]['productId'] = 900;
    $origin = cnOrigin(attributes: ['payload' => ['invoice' => $frozen]]);

    expect(Moloni::creditNote($origin)->fresh()?->status)->toBe(DocumentStatus::Failed)
        ->and(moloniRequests('creditNotes/insert'))->toHaveCount(0);
});

it('credits a zero-rated line with its exemption', function () {
    $frozen = (new InvoiceData(
        new CustomerData('C1', '509999999', 'Acme Lda', null, null, null, null),
        [new InvoiceLine('Export', 1, '8.13008', 0, 'M07')],
    ))->toArray();
    cnFake(8.13, originOverrides: ['products' => [['document_product_id' => 50001, 'product_id' => 900, 'name' => 'Export', 'qty' => 1, 'price' => 8.13008, 'exemption_reason' => 'M07', 'taxes' => []]]]);
    $origin = cnOrigin(attributes: ['payload' => ['invoice' => $frozen], 'expected_total_cents' => 813]);

    $credit = Moloni::creditNote($origin)->fresh();

    expect(moloniRequests('creditNotes/insert')[0]['products'])->toBe([
        ['product_id' => 900, 'name' => 'Export', 'qty' => 1.0, 'price' => 8.13008, 'related_id' => 50001, 'taxes' => [], 'exemption_reason' => 'M07'],
    ])
        ->and($credit?->status)->toBe(DocumentStatus::Issued)
        ->and($credit?->error_type)->toBeNull();
});

it('takes the Moloni customer of an adopted origin from Moloni', function () {
    cnFake(12.3);
    $origin = cnOrigin(attributes: ['customer_id' => null]);

    $credit = Moloni::creditNote($origin, [new CreditLine(0, 1)])->fresh();

    expect(moloniRequests('creditNotes/insert')[0]['customer_id'])->toBe(301)
        ->and($credit?->customer_id)->toBe(301)
        ->and($credit?->status)->toBe(DocumentStatus::Issued);
});

it('issues the credit note without a maturity date or expiration date, which Moloni does not need', function () {
    cnFake(49.2);
    $origin = cnOrigin();
    useMoloniSettings(['maturity_dates' => [30 => 12]]);

    $credit = Moloni::creditNote($origin)->fresh();
    $inserts = moloniRequests('creditNotes/insert');

    expect($credit?->status)->toBe(DocumentStatus::Issued)
        ->and($inserts)->toHaveCount(1)
        ->and($inserts[0]->data())->not->toHaveKeys(['expiration_date'])
        ->and($inserts[0]->data())->not->toHaveKeys(['maturity_date_id']);
});

it('fails when Moloni refuses the credit note', function () {
    cnFake(12.3, ['*/creditNotes/insert/*' => [['code' => '5 related_id', 'description' => "Field 'related_id' must be valid"]]]);
    $origin = cnOrigin();

    $credit = Moloni::creditNote($origin, [new CreditLine(0, 1)])->fresh();

    expect($credit?->status)->toBe(DocumentStatus::Failed)
        ->and($credit?->error_type)->toBe(FailureType::Validation->value)
        ->and($credit?->error_message)->toContain('related_id')
        ->and($origin->outstandingCents())->toBe(4920);
});

it('leaves a credit note whose insert response was lost unknown, then adopts it by reference', function () {
    cnFake(12.3, [
        '*/creditNotes/insert/*' => Http::response('Bad gateway', 502),
        '*/documents/getAll/*' => Http::sequence()->push([])->push([
            // Another type with the same reference in the same series is not this one.
            moloniDocumentRow(['document_id' => 9600, 'document_type_id' => 1, 'document_set_id' => 44, 'our_reference' => '#ORDER-1/NC1', 'net_value' => 12.3]),
            moloniDocumentRow(['document_id' => 9601, 'document_type_id' => 3, 'document_set_id' => 44, 'document_set_name' => 'NC', 'number' => 4, 'our_reference' => '#ORDER-1/NC1', 'net_value' => 12.3]),
        ]),
    ]);
    $origin = cnOrigin();

    $credit = Moloni::creditNote($origin, [new CreditLine(0, 1)])->refresh();

    expect($credit->status)->toBe(DocumentStatus::Unknown)
        ->and($credit->insert_sent_at)->not->toBeNull()
        ->and($origin->outstandingCents())->toBe(4920 - 1230);

    runIssueJob($credit);

    $credit->refresh();
    expect($credit->status)->toBe(DocumentStatus::Issued)
        ->and($credit->document_id)->toBe(9601)
        ->and($credit->document_number)->toBe('NC/4')
        ->and(moloniRequests('creditNotes/insert'))->toHaveCount(1);
    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/documents/getAll/') && $request['our_reference'] === '#ORDER-1/NC1');
});

it('blocks a credit note whose type in Moloni cannot be read', function () {
    cnFake(12.3, ['*/documents/getAll/*' => Http::response([
        moloniDocumentRow(['document_id' => 9601, 'document_type_id' => null, 'document_set_id' => 44, 'our_reference' => '#ORDER-1/NC1', 'net_value' => 12.3]),
    ])]);
    $credit = cnPending(cnOrigin(), [new CreditLine(0, 1)]);

    runIssueJob($credit);

    expect($credit->fresh()?->status)->toBe(DocumentStatus::Unknown)
        ->and($credit->fresh()?->error_type)->toBe('ambiguous_reconciliation')
        ->and(moloniRequests('creditNotes/insert'))->toHaveCount(0);
});

it('explains a refused credit in Portuguese', function () {
    Queue::fake();
    app()->setLocale('pt');
    $origin = cnOrigin();

    expect(fn () => Moloni::creditNote($origin, [new CreditLine(0, 4)]))
        ->toThrow(InvalidArgumentException::class, 'A linha 0 de GR/8 só pode ser creditada em mais 3.');
});

it('numbers credit notes across the documents that share a reference', function () {
    Http::fake();
    $origin = cnOrigin();
    cnPending($origin, [new CreditLine(0, 1)])->fill(['status' => DocumentStatus::Unknown])->save();
    // A reissue: the new invoice takes the same reference.
    $origin->fill(['status' => DocumentStatus::Superseded])->save();
    $reissued = cnOrigin(attributes: ['document_id' => 8002, 'document_number' => 'GR/9']);

    expect(cnPending($reissued, [new CreditLine(0, 1)])->reference)->toBe('#ORDER-1/NC2');
});
