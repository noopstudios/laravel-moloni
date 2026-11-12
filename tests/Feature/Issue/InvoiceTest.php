<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Noop\Moloni\Enums\DocumentStatus;
use Noop\Moloni\Enums\DocumentType;
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
    travelTo(Carbon::parse('2026-05-04 23:30:00'));
    Event::fake([DocumentIssued::class, DocumentFailed::class]);
});

it('issues an invoice with the maturity date for 30 days', function () {
    fakeMoloniIssuing(['*/documents/getOne/*' => moloniDocumentRow(['document_set_id' => 41])]);
    $order = Order::create(['customer_vat' => '509999999']);

    $document = Moloni::invoice($order, 30)->fresh();

    $inserts = moloniRequests('invoices/insert');
    expect($inserts)->toHaveCount(1)
        ->and(moloniRequests('invoiceReceipts/insert'))->toHaveCount(0);
    $sent = $inserts[0]->data();
    expect($sent['date'])->toBe('2026-05-04')
        ->and($sent['expiration_date'])->toBe('2026-06-03')
        ->and($sent['maturity_date_id'])->toBe(12)
        ->and($sent['document_set_id'])->toBe(41)
        ->and($sent['our_reference'])->toBe('#ORDER-1')
        ->and($sent['status'])->toBe(1)
        ->and($sent)->not->toHaveKey('payments')
        ->and($sent)->not->toHaveKey('send_email')
        ->and($document?->type)->toBe(DocumentType::Invoice)
        ->and($document?->status)->toBe(DocumentStatus::Issued)
        ->and($document?->due_at?->toDateString())->toBe('2026-06-03')
        ->and($document?->operation_key)->toBe(InvoiceDocument::operationKey(DocumentType::Invoice, $order, 1))
        ->and($document?->payload)->toMatchArray(['payment' => null, 'due_in_days' => 30]);
});

it('dates the document in the application timezone', function () {
    config(['app.timezone' => 'Europe/Lisbon']);
    travelTo(Carbon::parse('2026-05-04 23:30:00', 'UTC'));
    fakeMoloniIssuing(['*/documents/getOne/*' => moloniDocumentRow(['document_set_id' => 41])]);

    Moloni::invoice(Order::create(['customer_vat' => '509999999']));

    expect(moloniRequests('invoices/insert')[0]['date'])->toBe('2026-05-05');
});

it('fails with Validation when no maturity date is configured for the term', function () {
    Http::fake();
    Queue::fake();

    $e = catchMoloniException(fn () => Moloni::invoice(Order::create(['customer_vat' => '509999999']), 45));

    expect($e->type)->toBe(FailureType::Validation)
        ->and($e->getMessage())->toContain('45')
        ->and(InvoiceDocument::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('fails the job when the maturity date was removed after the request', function () {
    fakeMoloniIssuing();
    Queue::fake();
    $document = Moloni::invoice(Order::create(['customer_vat' => '509999999']), 30);
    useMoloniSettings(['maturity_dates' => [0 => 11]]);

    runIssueJob($document);

    expect($document->fresh()?->status)->toBe(DocumentStatus::Failed)
        ->and(moloniRequests('invoices/insert'))->toHaveCount(0);
});

it('refuses a negative term', function () {
    Queue::fake();

    expect(fn () => Moloni::invoice(Order::create(), -1))->toThrow(InvalidArgumentException::class);
});

it('explains a refused request in Portuguese', function () {
    Queue::fake();
    app()->setLocale('pt');

    expect(catchMoloniException(fn () => Moloni::invoice(Order::create(), 45))->getMessage())
        ->toBe('Não está configurado nenhum prazo de vencimento de 45 dias.');
});
