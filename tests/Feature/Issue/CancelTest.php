<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Noop\Moloni\Data\CustomerData;
use Noop\Moloni\Data\InvoiceData;
use Noop\Moloni\Data\InvoiceLine;
use Noop\Moloni\Enums\DocumentStatus;
use Noop\Moloni\Enums\DocumentType;
use Noop\Moloni\Enums\FailureType;
use Noop\Moloni\Events\DocumentFailed;
use Noop\Moloni\Events\DocumentIssued;
use Noop\Moloni\Events\DocumentVoided;
use Noop\Moloni\Facades\Moloni;
use Noop\Moloni\Models\InvoiceDocument;
use Noop\Moloni\Models\InvoiceDocumentLink;
use Noop\Moloni\Tests\Fixtures\Order;

use function Pest\Laravel\travelTo;

beforeEach(function () {
    Http::preventStrayRequests();
    useIssueSettings(['document_sets' => ['invoice' => 41, 'invoice_receipt' => 42, 'receipt' => 43, 'credit_note' => 44]]);
    travelTo(Carbon::parse('2026-05-04 10:00:00'));
    Event::fake([DocumentIssued::class, DocumentFailed::class, DocumentVoided::class]);
});

/**
 * An issued document (Moloni id 8001, total 123.00) of customer 301 for a new order.
 *
 * @param  array<string, mixed>  $attributes
 */
function cxIssued(DocumentType $type = DocumentType::Invoice, array $attributes = []): InvoiceDocument
{
    $order = Order::create(['customer_vat' => '509999999']);

    return InvoiceDocument::factory()->issued()->create([
        'type' => $type,
        'invoiceable_type' => $order->getMorphClass(),
        'invoiceable_id' => $order->id,
        'company_id' => 5,
        'document_set_id' => 41,
        'document_id' => 8001,
        'document_number' => 'GR/8',
        'reference' => '#ORDER-1',
        'expected_total_cents' => 12300,
        'customer_id' => 301,
        'customer_vat' => '509999999',
        'payload' => ['invoice' => (new InvoiceData(
            new CustomerData('C1', '509999999', 'Acme Lda', null, null, null, null),
            [new InvoiceLine('Consulting', 1, '100', 23)],
        ))->toArray()],
        ...$attributes,
    ]);
}

/**
 * A document of $type acting on $document through a link, in $status.
 */
function cxDependant(InvoiceDocument $document, DocumentType $type, DocumentStatus $status = DocumentStatus::Issued, int $cents = 12300): InvoiceDocument
{
    $dependant = InvoiceDocument::factory()->issued()->create([
        'type' => $type,
        'status' => $status,
        'company_id' => 5,
        'reference' => '#DEP-'.$type->value,
        'document_number' => null,
        'expected_total_cents' => $cents,
    ]);
    InvoiceDocumentLink::create(['document_id' => $dependant->id, 'associated_document_id' => $document->id, 'amount_cents' => $cents]);

    return $dependant;
}

/**
 * @param  array<string, mixed>  $overrides
 */
function cxFake(array $overrides = []): void
{
    Http::fake([
        '*/grant/*' => moloniGrant(),
        '*/documents/documentCancel/*' => ['valid' => 1],
        ...$overrides,
    ]);
}

it('voids a document when Moloni accepts the cancellation and frees the live slot', function (DocumentType $type) {
    cxFake();
    $document = cxIssued($type);
    $order = $document->invoiceable;

    $voided = Moloni::cancel($document, 'Order cancelled by the customer');

    $requests = moloniRequests('documents/documentCancel');
    expect($requests)->toHaveCount(1)
        ->and($requests[0]->data())->toBe(['document_id' => 8001, 'observation' => 'Order cancelled by the customer', 'company_id' => 5])
        ->and($voided->is($document))->toBeTrue()
        ->and($voided->status)->toBe(DocumentStatus::Voided)
        ->and($voided->fresh()?->status)->toBe(DocumentStatus::Voided)
        ->and($voided->fresh()?->active_key)->toBeNull()
        ->and($voided->payload['void'] ?? null)->toBe(['reason' => 'Order cancelled by the customer', 'at' => '2026-05-04T10:00:00+00:00'])
        ->and($voided->payload['invoice'] ?? null)->not->toBeNull()
        ->and($voided->document_id)->toBe(8001);
    Event::assertDispatched(DocumentVoided::class, fn (DocumentVoided $event) => $event->document->is($document));

    // The order can be invoiced again.
    Queue::fake();
    $new = Moloni::invoice($order instanceof Order ? $order : throw new LogicException);
    expect($new->is($document))->toBeFalse()
        ->and($new->status)->toBe(DocumentStatus::Pending);
})->with([DocumentType::Invoice, DocumentType::InvoiceReceipt]);

it('throws Validation with Moloni\'s reason when the cancellation is refused', function () {
    cxFake(['*/documents/documentCancel/*' => ['valid' => 0, 'message' => [['code' => '10 associated_id', 'description' => 'The associated document is not valid.']]]]);
    $document = cxIssued();

    $e = catchMoloniException(fn () => Moloni::cancel($document, 'Order cancelled'));

    expect($e->type)->toBe(FailureType::Validation)
        ->and($e->getMessage())->toContain('GR/8')
        ->and($e->getMessage())->toContain('The associated document is not valid.')
        ->and($document->fresh()?->status)->toBe(DocumentStatus::Issued)
        ->and($document->fresh()?->payload)->not->toHaveKey('void')
        ->and(InvoiceDocument::count())->toBe(1);
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/creditNotes/'));
    Event::assertNotDispatched(DocumentVoided::class);
});

it('refuses to credit or cancel a document that is not issued', function (array $attributes) {
    Http::fake();
    Queue::fake();
    $document = cxIssued(attributes: $attributes);

    expect(catchMoloniException(fn () => Moloni::cancel($document, 'Order cancelled'))->type)->toBe(FailureType::Validation)
        ->and(fn () => Moloni::creditNote($document))->toThrow(InvalidArgumentException::class)
        ->and($document->fresh()?->status)->toBe($attributes['status'] ?? DocumentStatus::Issued)
        ->and(InvoiceDocument::count())->toBe(1);
    Http::assertNothingSent();
    Event::assertNotDispatched(DocumentVoided::class);
})->with([
    'pending' => [['status' => DocumentStatus::Pending, 'document_id' => null]],
    'issuing' => [['status' => DocumentStatus::Issuing, 'document_id' => null]],
    'failed' => [['status' => DocumentStatus::Failed, 'document_id' => null]],
    'unknown' => [['status' => DocumentStatus::Unknown, 'document_id' => null]],
    'voided' => [['status' => DocumentStatus::Voided]],
    'superseded' => [['status' => DocumentStatus::Superseded]],
    'issued without a Moloni id' => [['document_id' => null]],
]);

it('refuses to cancel a document issued for another company', function () {
    Http::fake();
    $document = cxIssued(attributes: ['company_id' => 6]);

    expect(catchMoloniException(fn () => Moloni::cancel($document, 'Order cancelled'))->type)->toBe(FailureType::Validation)
        ->and($document->fresh()?->status)->toBe(DocumentStatus::Issued);
    Http::assertNothingSent();
});

it('judges the document as stored, not the copy it was given', function () {
    Http::fake();
    $document = cxIssued();
    $stale = InvoiceDocument::findOrFail($document->id);
    $document->fill(['status' => DocumentStatus::Superseded])->save();

    expect(catchMoloniException(fn () => Moloni::cancel($stale, 'Order cancelled'))->type)->toBe(FailureType::Validation);
    Http::assertNothingSent();
});

it('refuses to cancel what a live document depends on, without calling Moloni', function (DocumentType $type, DocumentType $dependantType, DocumentStatus $status) {
    Http::fake();
    $document = cxIssued($type);
    cxDependant($document, $dependantType, $status);

    $e = catchMoloniException(fn () => Moloni::cancel($document, 'Order cancelled'));

    expect($e->type)->toBe(FailureType::Validation)
        ->and($e->getMessage())->toContain('#DEP-'.$dependantType->value)
        ->and($document->fresh()?->status)->toBe(DocumentStatus::Issued);
    Http::assertNothingSent();
})->with([
    'an issued receipt' => [DocumentType::Invoice, DocumentType::Receipt, DocumentStatus::Issued],
    'a pending receipt' => [DocumentType::Invoice, DocumentType::Receipt, DocumentStatus::Pending],
    'an unknown credit note' => [DocumentType::Invoice, DocumentType::CreditNote, DocumentStatus::Unknown],
    'a credit note on an invoice-receipt' => [DocumentType::InvoiceReceipt, DocumentType::CreditNote, DocumentStatus::Issued],
    'a conversion' => [DocumentType::Proforma, DocumentType::Invoice, DocumentStatus::Issued],
]);

it('names what must be cancelled first in Portuguese', function () {
    Http::fake();
    app()->setLocale('pt');
    $document = cxIssued();
    cxDependant($document, DocumentType::Receipt);

    expect(catchMoloniException(fn () => Moloni::cancel($document, 'Encomenda anulada'))->getMessage())
        ->toBe('Não é possível anular GR/8 enquanto dependerem dele: Recibo #DEP-receipt. Anule-os primeiro ou emita uma nota de crédito.');
});

it('cancels a document whose dependants no longer count', function (DocumentStatus $status) {
    cxFake();
    $document = cxIssued();
    cxDependant($document, DocumentType::Receipt, $status);

    expect(Moloni::cancel($document, 'Order cancelled')->status)->toBe(DocumentStatus::Voided);
})->with([DocumentStatus::Failed, DocumentStatus::Voided, DocumentStatus::Superseded]);

it('voids a document whose cancellation was uncertain once Moloni shows it cancelled', function () {
    cxFake([
        '*/documents/documentCancel/*' => Http::response('Bad gateway', 502),
        '*/documents/getOne/*' => moloniDocumentRow(['document_id' => 8001, 'status' => 2]),
    ]);
    $document = cxIssued();

    expect(Moloni::cancel($document, 'Order cancelled')->status)->toBe(DocumentStatus::Voided)
        ->and($document->fresh()?->status)->toBe(DocumentStatus::Voided);
    Event::assertDispatched(DocumentVoided::class);
});

it('keeps the document and rethrows an uncertain cancellation Moloni does not show', function (mixed $readBack) {
    cxFake([
        '*/documents/documentCancel/*' => Http::response('Bad gateway', 502),
        '*/documents/getOne/*' => $readBack,
    ]);
    $document = cxIssued();

    $e = catchMoloniException(fn () => Moloni::cancel($document, 'Order cancelled'));

    expect($e->type)->toBe(FailureType::Unknown)
        ->and($document->fresh()?->status)->toBe(DocumentStatus::Issued)
        ->and($document->fresh()?->payload)->not->toHaveKey('void');
    Event::assertNotDispatched(DocumentVoided::class);
})->with([
    'still closed' => [fn () => moloniDocumentRow(['document_id' => 8001, 'status' => 1])],
    'another document' => [fn () => moloniDocumentRow(['document_id' => 8002, 'status' => 2])],
    'unreadable' => [fn () => Http::response('Unavailable', 503)],
]);

it('restores what an invoice owes when its receipt is voided', function (DocumentType $type) {
    cxFake();
    $invoice = cxIssued(attributes: ['document_id' => 8002]);
    $dependant = cxDependant($invoice, $type, cents: 10000);
    $dependant->fill(['document_id' => 9001])->save();
    expect($invoice->outstandingCents())->toBe(2300);

    Moloni::cancel($dependant, 'Paid by mistake');

    expect($dependant->status)->toBe(DocumentStatus::Voided)
        ->and($invoice->outstandingCents())->toBe(12300)
        ->and(moloniRequests('documents/documentCancel')[0]['document_id'])->toBe(9001);
})->with([DocumentType::Receipt, DocumentType::CreditNote]);

it('requires a reason', function () {
    Http::fake();

    expect(fn () => Moloni::cancel(cxIssued(), '  '))->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
});

it('never calls Moloni inside a database transaction', function () {
    Http::fake();
    $document = cxIssued();

    expect(fn () => DB::transaction(fn () => Moloni::cancel($document, 'Order cancelled')))->toThrow(LogicException::class)
        ->and($document->fresh()?->status)->toBe(DocumentStatus::Issued);
    Http::assertNothingSent();
});

it('refuses to cancel a document being processed', function () {
    Http::fake();
    $document = cxIssued();
    $lock = Cache::lock("moloni-doc-{$document->id}", 150);
    $lock->get();

    expect(catchMoloniException(fn () => Moloni::cancel($document, 'Order cancelled'))->type)->toBe(FailureType::Validation);
    Http::assertNothingSent();
    $lock->release();
});
