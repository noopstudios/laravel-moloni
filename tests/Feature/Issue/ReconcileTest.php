<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Noop\Moloni\Data\PaymentData;
use Noop\Moloni\Enums\DocumentStatus;
use Noop\Moloni\Enums\FailureType;
use Noop\Moloni\Events\DocumentFailed;
use Noop\Moloni\Events\DocumentIssued;
use Noop\Moloni\Facades\Moloni;
use Noop\Moloni\Models\InvoiceDocument;
use Noop\Moloni\Tests\Fixtures\Order;

use function Pest\Laravel\travelTo;

beforeEach(function () {
    Http::preventStrayRequests();
    useIssueSettings();
    Order::$invoiceDataUsing = null;
    travelTo(Carbon::parse('2026-05-04 10:00:00'));
    Event::fake([DocumentIssued::class, DocumentFailed::class]);
});

/**
 * An invoice-receipt for 123.00 to VAT 509999999 whose insert was sent and may exist in Moloni.
 *
 * @param  array<string, mixed>  $attributes
 */
function rcUncertain(DocumentStatus $status = DocumentStatus::Unknown, string $vat = '509999999', array $attributes = []): InvoiceDocument
{
    Queue::fake();
    $document = Moloni::invoiceReceipt(Order::create(['customer_vat' => $vat]), new PaymentData('card', Carbon::now(), 12300));
    $document->fill(['status' => $status, 'customer_id' => 301, 'customer_number' => 'GR26-C1', 'attempts' => 1, ...$attributes])->save();

    return $document;
}

it('adopts the existing Moloni document on the next run instead of inserting again', function () {
    fakeMoloniIssuing(['*/invoiceReceipts/getAll/*' => [moloniDocumentRow(['document_id' => 7005, 'number' => 9])]]);
    $document = rcUncertain();

    runIssueJob($document);

    $document->refresh();
    expect($document->status)->toBe(DocumentStatus::Issued)
        ->and($document->document_id)->toBe(7005)
        ->and($document->document_number)->toBe('GR/9')
        ->and($document->issued_at?->toDateTimeString())->toBe('2026-05-04 10:00:00')
        ->and($document->error_type)->toBeNull()
        ->and($document->error_message)->toBeNull()
        ->and($document->attempts)->toBe(2);
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/insert/'));
    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/invoiceReceipts/getAll/')
        && $request['our_reference'] === '#ORDER-1'
        && $request['company_id'] === 5);
    Event::assertDispatched(DocumentIssued::class);
});

it('reconciles a document left issuing by a job that stopped', function () {
    fakeMoloniIssuing(['*/invoiceReceipts/getAll/*' => [moloniDocumentRow()]]);
    $document = rcUncertain(DocumentStatus::Issuing);

    runIssueJob($document);

    expect($document->fresh()?->status)->toBe(DocumentStatus::Issued)
        ->and(moloniRequests('invoiceReceipts/insert'))->toHaveCount(0);
});

it('matches the reference exactly, ignoring #GR-10 when looking for #GR-1', function () {
    fakeMoloniIssuing(['*/invoiceReceipts/getAll/*' => [moloniDocumentRow(['document_id' => 7010, 'our_reference' => '#GR-10'])]]);
    $document = rcUncertain();
    $document->reference = '#GR-1';
    $document->save();

    runIssueJob($document);

    $document->refresh();
    expect($document->status)->toBe(DocumentStatus::Issued)
        ->and($document->document_id)->toBe(7001)
        ->and($document->attempts)->toBe(2)
        ->and(moloniRequests('invoiceReceipts/insert'))->toHaveCount(1)
        ->and(moloniRequests('invoiceReceipts/insert')[0]['our_reference'])->toBe('#GR-1');
});

it('issues when Moloni has nothing that could be this document', function (array $row) {
    fakeMoloniIssuing(['*/invoiceReceipts/getAll/*' => [moloniDocumentRow(['document_id' => 7009, ...$row])]]);
    InvoiceDocument::factory()->invoiceReceipt()->issued()->create(['company_id' => 5, 'document_id' => 7777]);
    $document = rcUncertain();

    runIssueJob($document);

    expect($document->fresh()?->document_id)->toBe(7001)
        ->and(moloniRequests('invoiceReceipts/insert'))->toHaveCount(1);
})->with([
    'cancelled' => [['status' => 2]],
    'draft' => [['status' => 0]],
    'another series' => [['document_set_id' => 41]],
    'another reference' => [['our_reference' => '#ORDER-10']],
    'already recorded on another row' => [['document_id' => 7777]],
]);

it('blocks the insert when a document with the reference cannot be confirmed as this one', function (DocumentStatus $status, array $row) {
    fakeMoloniIssuing(['*/invoiceReceipts/getAll/*' => [array_filter(
        moloniDocumentRow(['document_id' => 7009, ...$row]),
        fn (mixed $value) => $value !== null,
    )]]);
    $document = rcUncertain($status);

    runIssueJob($document);

    $document->refresh();
    expect($document->status)->toBe(DocumentStatus::Unknown)
        ->and($document->error_type)->toBe('ambiguous_reconciliation')
        ->and($document->document_id)->toBeNull()
        ->and(moloniRequests('invoiceReceipts/insert'))->toHaveCount(0);
    Event::assertDispatched(DocumentFailed::class);
})->with([
    'another VAT' => [DocumentStatus::Unknown, ['entity_vat' => '501111111']],
    'another total' => [DocumentStatus::Unknown, ['net_value' => 123.01]],
    'a pending row and another total' => [DocumentStatus::Pending, ['net_value' => 99]],
    'no VAT in the row' => [DocumentStatus::Issuing, ['entity_vat' => null]],
    'no total in the row' => [DocumentStatus::Issuing, ['net_value' => null]],
    'no series in the row' => [DocumentStatus::Issuing, ['document_set_id' => null]],
    'no id in the row' => [DocumentStatus::Issuing, ['document_id' => null]],
    'no status in the row' => [DocumentStatus::Issuing, ['status' => null, 'net_value' => 1]],
]);

it('looks only at documents of the same type', function () {
    fakeMoloniIssuing([
        '*/invoices/getAll/*' => [moloniDocumentRow(['document_id' => 7005])],
        '*/proFormaInvoices/getAll/*' => [moloniDocumentRow(['document_id' => 7006])],
    ]);
    $document = rcUncertain();

    runIssueJob($document);

    expect($document->fresh()?->document_id)->toBe(7001)
        ->and(moloniRequests('invoiceReceipts/getAll'))->toHaveCount(1)
        ->and(moloniRequests('invoices/getAll'))->toHaveCount(0)
        ->and(moloniRequests('proFormaInvoices/getAll'))->toHaveCount(0)
        ->and(moloniRequests('invoiceReceipts/insert'))->toHaveCount(1);
});

it('looks for an invoice among invoices only', function () {
    fakeMoloniIssuing([
        '*/invoiceReceipts/getAll/*' => [moloniDocumentRow(['document_id' => 7005, 'document_set_id' => 41])],
        '*/invoices/getAll/*' => Http::response([]),
        '*/documents/getOne/*' => moloniDocumentRow(['document_set_id' => 41]),
    ]);

    $document = Moloni::invoice(Order::create(['customer_vat' => '509999999']))->refresh();

    expect($document->document_id)->toBe(7001)
        ->and(moloniRequests('invoices/getAll'))->toHaveCount(1)
        ->and(moloniRequests('invoiceReceipts/getAll'))->toHaveCount(0)
        ->and(moloniRequests('invoices/insert'))->toHaveCount(1);
});

it('looks in Moloni before the first insert as well', function () {
    fakeMoloniIssuing(['*/invoiceReceipts/getAll/*' => [moloniDocumentRow(['document_id' => 7005])]]);

    $document = Moloni::invoiceReceipt(Order::create(['customer_vat' => '509999999']), new PaymentData('card', Carbon::now(), 12300))->refresh();

    expect($document->document_id)->toBe(7005)
        ->and(moloniRequests('invoiceReceipts/insert'))->toHaveCount(0);
});

it('stays unknown when two closed documents share the reference', function () {
    fakeMoloniIssuing(['*/invoiceReceipts/getAll/*' => [
        moloniDocumentRow(['document_id' => 7005]),
        moloniDocumentRow(['document_id' => 7006, 'entity_vat' => 'PT509999999', 'net_value' => '123.00']),
    ]]);
    $document = rcUncertain(DocumentStatus::Issuing);

    runIssueJob($document);

    $document->refresh();
    expect($document->status)->toBe(DocumentStatus::Unknown)
        ->and($document->error_type)->toBe('ambiguous_reconciliation')
        ->and($document->document_id)->toBeNull()
        ->and(moloniRequests('invoiceReceipts/insert'))->toHaveCount(0);
    Event::assertDispatched(DocumentFailed::class);
    Event::assertNotDispatched(DocumentIssued::class);
});

it('matches a final consumer by customer id when it is known, else by the generic VAT', function (?int $customerId, array $row, bool $adopted) {
    fakeMoloniIssuing([
        '*/invoiceReceipts/getAll/*' => [moloniDocumentRow(['document_id' => 7005, ...$row])],
        '*/customers/getByNumber/*' => [['customer_id' => 301, 'number' => 'GR26-C1F', 'vat' => '999999990', 'name' => 'Acme Lda']],
    ]);
    $document = rcUncertain(vat: '999999990', attributes: ['customer_id' => $customerId]);

    runIssueJob($document);

    $document->refresh();
    expect($document->document_id)->toBe($adopted ? 7005 : null)
        ->and($document->status)->toBe($adopted ? DocumentStatus::Issued : DocumentStatus::Unknown)
        ->and(moloniRequests('invoiceReceipts/insert'))->toHaveCount(0);
})->with([
    'same customer' => [301, ['entity_vat' => '999999990'], true],
    'another customer' => [301, ['customer_id' => 302, 'entity_vat' => '999999990'], false],
    'generic VAT' => [null, ['customer_id' => 302, 'entity_vat' => '999999990'], true],
    'a business VAT' => [null, ['entity_vat' => '509999999'], false],
]);

it('keeps the status and retries later when the reconciliation read fails', function (DocumentStatus $status) {
    fakeMoloniIssuing(['*/invoiceReceipts/getAll/*' => Http::response('Unavailable', 503)]);
    $document = rcUncertain($status);

    $e = catchMoloniException(fn () => runIssueJob($document, attempt: 3));

    expect($e->type)->toBe(FailureType::Transient)
        ->and($document->fresh()?->status)->toBe($status)
        ->and(moloniRequests('invoiceReceipts/insert'))->toHaveCount(0);
    Event::assertNotDispatched(DocumentFailed::class);
})->with([DocumentStatus::Issuing, DocumentStatus::Unknown]);

it('never fails a document that may exist when the reconciliation cannot be read', function (DocumentStatus $status, int $attempts, mixed $response, DocumentStatus $expected) {
    fakeMoloniIssuing(['*/invoiceReceipts/getAll/*' => $response]);
    $document = rcUncertain($status, attributes: ['attempts' => $attempts]);

    runIssueJob($document);

    $document->refresh();
    expect($document->status)->toBe($expected)
        ->and($document->error_type)->not->toBeNull()
        ->and(moloniRequests('invoiceReceipts/insert'))->toHaveCount(0);
    Event::assertDispatched(DocumentFailed::class);
})->with([
    'issuing, access refused' => [DocumentStatus::Issuing, 0, fn () => Http::response(['error' => 'Forbidden'], 403), DocumentStatus::Unknown],
    'unknown, access refused' => [DocumentStatus::Unknown, 0, fn () => Http::response(['error' => 'Forbidden'], 403), DocumentStatus::Unknown],
    'issuing, last attempt' => [DocumentStatus::Issuing, 2, fn () => Http::response('Unavailable', 503), DocumentStatus::Unknown],
    'pending, access refused' => [DocumentStatus::Pending, 0, fn () => Http::response(['error' => 'Forbidden'], 403), DocumentStatus::Failed],
]);

it('never adopts the Moloni document of the superseded row', function () {
    fakeMoloniIssuing([
        '*/invoiceReceipts/getAll/*' => Http::sequence()->push([])->push([moloniDocumentRow()]),
        '*/invoiceReceipts/insert/*' => Http::sequence()
            ->push(['valid' => 1, 'document_id' => 7001])
            ->push(['valid' => 1, 'document_id' => 7002]),
        '*/documents/getOne/*' => fn (Request $request) => Http::response(moloniDocumentRow(['document_id' => $request['document_id']])),
    ]);
    $old = Moloni::invoiceReceipt(Order::create(['customer_vat' => '509999999']), new PaymentData('card', Carbon::now(), 12300));
    expect($old->fresh()?->document_id)->toBe(7001);

    Queue::fake();
    $new = Moloni::reissue($old);
    $new->fill(['status' => DocumentStatus::Unknown, 'customer_id' => 301])->save();

    runIssueJob($new);

    expect($new->fresh()?->document_id)->toBe(7002)
        ->and($new->fresh()?->status)->toBe(DocumentStatus::Issued)
        ->and(moloniRequests('invoiceReceipts/getAll'))->toHaveCount(2);
});

it('adopts what the reissued unknown document left in Moloni, or issues it once', function (bool $landed) {
    fakeMoloniIssuing([
        '*/invoiceReceipts/getAll/*' => $landed ? [moloniDocumentRow(['document_id' => 7005])] : Http::response([]),
    ]);
    $old = rcUncertain();

    $new = Moloni::reissue($old);
    runIssueJob($new);

    $new->refresh();
    expect($old->fresh()?->status)->toBe(DocumentStatus::Superseded)
        ->and($new->status)->toBe(DocumentStatus::Issued)
        ->and($new->document_id)->toBe($landed ? 7005 : 7001)
        ->and(moloniRequests('invoiceReceipts/insert'))->toHaveCount($landed ? 0 : 1);
})->with(['landed' => [true], 'not landed' => [false]]);
