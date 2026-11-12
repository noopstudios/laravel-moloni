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
    fakeMoloniIssuing(['*/documents/getAll/*' => [moloniDocumentRow(['document_id' => 7005, 'number' => 9])]]);
    $document = rcUncertain();

    runIssueJob($document);

    $document->refresh();
    expect($document->status)->toBe(DocumentStatus::Issued)
        ->and($document->document_id)->toBe(7005)
        ->and($document->document_number)->toBe('GR/9')
        ->and($document->issued_at?->toDateTimeString())->toBe('2026-05-04 10:00:00')
        ->and($document->error_type)->toBeNull()
        ->and($document->error_message)->toBeNull()
        ->and($document->attempts)->toBe(1);
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/insert/'));
    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/documents/getAll/')
        && $request['our_reference'] === '#ORDER-1'
        && $request['company_id'] === 5);
    Event::assertDispatched(DocumentIssued::class);
});

it('reconciles a document left issuing by a job that stopped', function () {
    fakeMoloniIssuing(['*/documents/getAll/*' => [moloniDocumentRow()]]);
    $document = rcUncertain(DocumentStatus::Issuing);

    runIssueJob($document);

    expect($document->fresh()?->status)->toBe(DocumentStatus::Issued)
        ->and(moloniRequests('invoiceReceipts/insert'))->toHaveCount(0);
});

it('matches the reference exactly, ignoring #GR-10 when looking for #GR-1', function () {
    fakeMoloniIssuing(['*/documents/getAll/*' => [moloniDocumentRow(['document_id' => 7010, 'our_reference' => '#GR-10'])]]);
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

it('issues when Moloni has no document that matches', function (array $row) {
    fakeMoloniIssuing(['*/documents/getAll/*' => [moloniDocumentRow(['document_id' => 7009, ...$row])]]);
    InvoiceDocument::factory()->invoiceReceipt()->issued()->create(['company_id' => 5, 'document_id' => 7777]);
    $document = rcUncertain();

    runIssueJob($document);

    expect($document->fresh()?->document_id)->toBe(7001)
        ->and(moloniRequests('invoiceReceipts/insert'))->toHaveCount(1);
})->with([
    'cancelled' => [['status' => 2]],
    'draft' => [['status' => 0]],
    'another series' => [['document_set_id' => 41]],
    'another VAT' => [['entity_vat' => '501111111']],
    'another total' => [['net_value' => 123.01]],
    'already recorded on another row' => [['document_id' => 7777]],
]);

it('stays unknown when two closed documents share the reference', function () {
    fakeMoloniIssuing(['*/documents/getAll/*' => [
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
        '*/documents/getAll/*' => [moloniDocumentRow(['document_id' => 7005, ...$row])],
        '*/customers/getByNumber/*' => [['customer_id' => 301, 'number' => 'GR26-C1F', 'vat' => '999999990', 'name' => 'Acme Lda']],
    ]);
    $document = rcUncertain(vat: '999999990', attributes: ['customer_id' => $customerId]);

    runIssueJob($document);

    expect($document->fresh()?->document_id)->toBe($adopted ? 7005 : 7001);
})->with([
    'same customer' => [301, ['entity_vat' => '999999990'], true],
    'another customer' => [301, ['customer_id' => 302, 'entity_vat' => '999999990'], false],
    'generic VAT' => [null, ['customer_id' => 302, 'entity_vat' => '999999990'], true],
    'a business VAT' => [null, ['entity_vat' => '509999999'], false],
]);

it('keeps the status and retries later when the reconciliation read fails', function (int $attempt) {
    fakeMoloniIssuing(['*/documents/getAll/*' => Http::response('Unavailable', 503)]);
    $document = rcUncertain();

    $e = catchMoloniException(fn () => runIssueJob($document, $attempt));

    expect($e->type)->toBe(FailureType::Transient)
        ->and($document->fresh()?->status)->toBe(DocumentStatus::Unknown)
        ->and(moloniRequests('invoiceReceipts/insert'))->toHaveCount(0);
    Event::assertNotDispatched(DocumentFailed::class);
})->with([1, 3]);

it('never adopts the Moloni document of the superseded row', function () {
    fakeMoloniIssuing([
        // The first issue is Pending, so only the reissued row's reconciliation reads this list.
        '*/documents/getAll/*' => [moloniDocumentRow()],
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
        ->and(moloniRequests('documents/getAll'))->toHaveCount(1);
});
