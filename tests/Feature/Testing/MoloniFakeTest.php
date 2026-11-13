<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Noop\Moloni\Data\PaymentData;
use Noop\Moloni\Enums\DocumentStatus;
use Noop\Moloni\Enums\DocumentType;
use Noop\Moloni\Enums\FailureType;
use Noop\Moloni\Events\DocumentFailed;
use Noop\Moloni\Events\DocumentIssued;
use Noop\Moloni\Events\DocumentVoided;
use Noop\Moloni\Facades\Moloni;
use Noop\Moloni\Jobs\IssueDocument;
use Noop\Moloni\Models\InvoiceDocument;
use Noop\Moloni\Testing\MoloniFake;
use Noop\Moloni\Tests\Fixtures\Order;
use PHPUnit\Framework\AssertionFailedError;

function moloniFake(): MoloniFake
{
    $fake = Moloni::getFacadeRoot();

    return $fake instanceof MoloniFake ? $fake : throw new LogicException('Moloni::fake() was not called.');
}

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
    Event::fake([DocumentIssued::class, DocumentFailed::class, DocumentVoided::class]);
    // No credentials and no settings: the fake must not need either.
    Moloni::fake();
});

it('issues documents instantly without HTTP', function () {
    $order = Order::create(['customer_vat' => '509999999']);

    $document = Moloni::invoice($order, 30);

    expect(Moloni::getFacadeRoot())->toBeInstanceOf(MoloniFake::class)
        ->and($document->fresh()?->type)->toBe(DocumentType::Invoice)
        ->and($document->fresh()?->status)->toBe(DocumentStatus::Issued)
        ->and($document->fresh()?->document_id)->toBeInt()
        ->and($document->fresh()?->document_number)->not->toBeNull()
        ->and($document->fresh()?->issued_at)->not->toBeNull()
        ->and($document->reference)->toBe('#ORDER-1')
        ->and($document->expected_total_cents)->toBe(12300)
        ->and($document->payload)->toMatchArray(['due_in_days' => 30]);
    Http::assertNothingSent();
    Queue::assertNothingPushed();
    Event::assertDispatched(DocumentIssued::class, fn (DocumentIssued $event) => $event->document->is($document));
});

it('numbers documents sequentially and is idempotent per invoiceable', function () {
    $order = Order::create(['customer_vat' => '509999999']);
    $first = Moloni::invoice($order);
    $second = Moloni::invoiceReceipt(Order::create(['customer_vat' => '509999999']), new PaymentData('card', now(), 12300));

    expect($second->document_id)->toBe($first->document_id + 1)
        ->and($second->type)->toBe(DocumentType::InvoiceReceipt);

    $again = Moloni::invoice($order);

    expect($again->is($first))->toBeTrue();
    moloniFake()->assertIssuedCount(2);
});

it('issues pro-formas, receipts and credit notes, converts, reissues and cancels', function () {
    $order = Order::create(['customer_vat' => '509999999']);

    $proforma = Moloni::proforma($order, 'quote-1');
    $invoice = Moloni::convert($proforma);
    $receipt = Moloni::receipt($order, [[$invoice, 12300]], new PaymentData('transfer', now(), 12300));
    $credit = Moloni::creditNote($invoice);

    expect($proforma->status)->toBe(DocumentStatus::Issued)
        ->and($invoice->status)->toBe(DocumentStatus::Issued)
        ->and($receipt->status)->toBe(DocumentStatus::Issued)
        ->and($credit->status)->toBe(DocumentStatus::Issued);

    Moloni::cancel($credit, 'Wrong note');
    Moloni::cancel($receipt, 'Wrong receipt');
    $new = Moloni::reissue($invoice);

    expect($credit->fresh()?->status)->toBe(DocumentStatus::Voided)
        ->and($invoice->fresh()?->status)->toBe(DocumentStatus::Superseded)
        ->and($new->status)->toBe(DocumentStatus::Issued);
    Event::assertDispatchedTimes(DocumentVoided::class, 2);
    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

it('returns a fixed PDF link', function () {
    $document = Moloni::invoice(Order::create(['customer_vat' => '509999999']));

    expect(Moloni::pdfLink($document))->toStartWith('https://')
        ->and(Moloni::pdfLink($document))->toBe(Moloni::pdfLink($document));
});

it('asserts on the type and the invoiceable of issued documents', function () {
    $order = Order::create(['customer_vat' => '509999999']);
    $other = Order::create(['customer_vat' => '509999999']);
    Moloni::invoice($order);

    moloniFake()->assertIssued(DocumentType::Invoice);
    moloniFake()->assertIssued(DocumentType::Invoice, fn (InvoiceDocument $document) => $document->invoiceable?->is($order) === true);
    moloniFake()->assertIssuedCount(1);

    expect(fn () => moloniFake()->assertIssued(DocumentType::Invoice, fn (InvoiceDocument $document) => $document->invoiceable?->is($other) === true))
        ->toThrow(AssertionFailedError::class)
        ->and(fn () => moloniFake()->assertIssued(DocumentType::Proforma))->toThrow(AssertionFailedError::class)
        ->and(fn () => moloniFake()->assertNothingIssued())->toThrow(AssertionFailedError::class)
        ->and(fn () => moloniFake()->assertIssuedCount(2))->toThrow(AssertionFailedError::class);
});

it('asserts nothing was issued', function () {
    moloniFake()->assertNothingIssued();
    moloniFake()->assertIssuedCount(0);
});

it('fails the next request with the chosen failure type', function () {
    moloniFake()->failNext(FailureType::Validation);

    $failed = Moloni::invoice(Order::create(['customer_vat' => '509999999']));
    $next = Moloni::invoice(Order::create(['customer_vat' => '509999999']));

    expect($failed->fresh()?->status)->toBe(DocumentStatus::Failed)
        ->and($failed->fresh()?->error_type)->toBe('validation')
        ->and($failed->fresh()?->error_message)->not->toBeEmpty()
        ->and($next->status)->toBe(DocumentStatus::Issued);
    Event::assertDispatched(DocumentFailed::class, fn (DocumentFailed $event) => $event->document->is($failed));
    moloniFake()->assertIssuedCount(1);
});

it('leaves an unknown outcome unknown, reconciles it and retries a failed one', function () {
    moloniFake()->failNext(FailureType::Unknown);
    $unknown = Moloni::invoice(Order::create(['customer_vat' => '509999999']));

    expect($unknown->fresh()?->status)->toBe(DocumentStatus::Unknown);

    Moloni::reconcile($unknown);

    expect($unknown->fresh()?->status)->toBe(DocumentStatus::Issued)
        ->and($unknown->fresh()?->document_id)->toBeInt();

    moloniFake()->failNext(FailureType::Authentication);
    $failed = Moloni::invoice(Order::create(['customer_vat' => '509999999']));

    expect($failed->fresh()?->status)->toBe(DocumentStatus::Failed);

    Moloni::retry($failed);

    expect($failed->fresh()?->status)->toBe(DocumentStatus::Issued)
        ->and($failed->fresh()?->error_type)->toBeNull();
    moloniFake()->assertIssuedCount(2);
});

it('cancels, retries and reconciles rows a host test seeded, whatever their company', function () {
    $issued = InvoiceDocument::factory()->invoice()->issued()->create(['company_id' => 77, 'document_id' => 5555]);
    $failed = InvoiceDocument::factory()->invoice()->failed()->create(['company_id' => 77]);
    $unknown = InvoiceDocument::factory()->receipt()->unknown()->create(['company_id' => 77]);

    expect(Moloni::cancel($issued, 'Order cancelled')->status)->toBe(DocumentStatus::Voided);

    Moloni::retry($failed);
    Moloni::reconcile($unknown);

    expect($failed->fresh()?->status)->toBe(DocumentStatus::Issued)
        ->and($unknown->fresh()?->status)->toBe(DocumentStatus::Issued);
    Event::assertDispatched(DocumentVoided::class);
});

it('fails only a request that reaches Moloni', function (Closure $request) {
    $order = Order::create(['customer_vat' => '509999999']);
    $first = Moloni::invoice($order);
    moloniFake()->failNext(FailureType::Validation);

    // Returns the live invoice, or is refused, before anything would be sent.
    try {
        $request($order);
    } catch (InvalidArgumentException) {
    }

    $next = Moloni::invoice(Order::create(['customer_vat' => '509999999']));

    expect($next->status)->toBe(DocumentStatus::Issued)
        ->and($first->fresh()?->status)->toBe(DocumentStatus::Issued);
    Event::assertNotDispatched(DocumentFailed::class);
})->with([
    'a request returning the live invoice' => [fn (Order $order) => Moloni::invoice($order)],
    'a request refused before it is recorded' => [fn (Order $order) => Moloni::invoice(Order::create(), -1)],
]);

it('never dispatches the issuing job', function () {
    Moloni::invoice(Order::create(['customer_vat' => '509999999']));

    Queue::assertNotPushed(IssueDocument::class);
});
