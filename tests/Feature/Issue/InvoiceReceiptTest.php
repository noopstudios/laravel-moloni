<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Noop\Moloni\Catalog\MoloniCatalog;
use Noop\Moloni\Data\CustomerData;
use Noop\Moloni\Data\InvoiceData;
use Noop\Moloni\Data\InvoiceLine;
use Noop\Moloni\Data\PaymentData;
use Noop\Moloni\Data\ResolvedCustomer;
use Noop\Moloni\Enums\DocumentStatus;
use Noop\Moloni\Enums\DocumentType;
use Noop\Moloni\Enums\FailureType;
use Noop\Moloni\Events\DocumentFailed;
use Noop\Moloni\Events\DocumentIssued;
use Noop\Moloni\Exceptions\MoloniException;
use Noop\Moloni\Facades\Moloni;
use Noop\Moloni\Http\MoloniConnector;
use Noop\Moloni\Jobs\IssueDocument;
use Noop\Moloni\Models\InvoiceDocument;
use Noop\Moloni\MoloniClient;
use Noop\Moloni\Resolvers\CustomerResolver;
use Noop\Moloni\Settings\MoloniSettings;
use Noop\Moloni\Tests\Fixtures\Order;

use function Pest\Laravel\travelTo;

beforeEach(function () {
    Http::preventStrayRequests();
    useIssueSettings();
    Order::$invoiceDataUsing = null;
    travelTo(Carbon::parse('2026-05-04 10:00:00'));
    Event::fake([DocumentIssued::class, DocumentFailed::class]);
});

function irPayment(int $cents = 12300, string $method = 'card'): PaymentData
{
    return new PaymentData($method, Carbon::parse('2026-05-04 09:30:00'), $cents);
}

/**
 * Requests the invoice-receipt with the queue faked, so the row stays pending.
 */
function irPending(?Order $order = null): InvoiceDocument
{
    Queue::fake();

    return Moloni::invoiceReceipt($order ?? Order::create(['customer_vat' => '509999999']), irPayment());
}

it('creates a pending row before any HTTP call and dispatches after commit', function () {
    Http::fake();
    Queue::fake();
    $order = Order::create(['customer_vat' => 'PT 509 999 999']);

    $document = Moloni::invoiceReceipt($order, irPayment());

    Http::assertNothingSent();
    expect($document->exists)->toBeTrue()
        ->and($document->status)->toBe(DocumentStatus::Pending)
        ->and($document->type)->toBe(DocumentType::InvoiceReceipt)
        ->and($document->operation_key)->toBe(InvoiceDocument::operationKey(DocumentType::InvoiceReceipt, $order, 1))
        ->and($document->active_key)->toBe(InvoiceDocument::activeKeyFor($order))
        ->and($document->company_id)->toBe(5)
        ->and($document->document_set_id)->toBe(42)
        ->and($document->reference)->toBe('#ORDER-1')
        ->and($document->expected_total_cents)->toBe(12300)
        ->and($document->customer_vat)->toBe('509999999')
        ->and($document->currency)->toBe('EUR')
        ->and($document->due_at?->toDateString())->toBe('2026-05-04')
        ->and(InvoiceData::fromArray($document->payload['invoice'] ?? [])->toArray())->toBe($order->toInvoiceData()->toArray())
        ->and(PaymentData::fromArray($document->payload['payment'] ?? [])->toArray())->toBe(irPayment()->toArray())
        ->and($document->payload['due_in_days'] ?? null)->toBe(0);
    Queue::assertPushed(IssueDocument::class, fn (IssueDocument $job) => $job->document->is($document)
        && $job->connection === 'sync'
        && $job->afterCommit === true);
});

it('runs the issuing job only once the caller\'s transaction commits', function () {
    fakeMoloniIssuing();

    $document = DB::transaction(function () {
        $document = Moloni::invoiceReceipt(Order::create(['customer_vat' => '509999999']), irPayment());

        expect(Http::recorded())->toHaveCount(0)
            ->and($document->fresh()?->status)->toBe(DocumentStatus::Pending);

        return $document;
    });

    expect($document->fresh()?->status)->toBe(DocumentStatus::Issued);
});

it('stores no VAT for a final consumer', function () {
    Queue::fake();

    expect(Moloni::invoiceReceipt(Order::create(['customer_vat' => '999999990']), irPayment())->customer_vat)->toBeNull();
});

it('issues an invoice-receipt with the payment block and marks it issued', function () {
    fakeMoloniIssuing();

    $document = Moloni::invoiceReceipt(Order::create(['customer_vat' => '509999999']), irPayment())->fresh();

    $inserts = moloniRequests('invoiceReceipts/insert');
    expect($inserts)->toHaveCount(1);
    $sent = $inserts[0]->data();
    expect($sent)->toBe([
        'company_id' => 5,
        'date' => '2026-05-04',
        'expiration_date' => '2026-05-04',
        'document_set_id' => 42,
        'customer_id' => 301,
        'our_reference' => '#ORDER-1',
        'maturity_date_id' => 11,
        'status' => 1,
        'products' => [[
            'product_id' => 900,
            'name' => 'Consulting',
            'summary' => '',
            'qty' => 1.0,
            'price' => '100',
            'taxes' => [['tax_id' => 82, 'value' => 23.0, 'order' => 1, 'cumulative' => 0]],
        ]],
        'payments' => [['payment_method_id' => 21, 'date' => '2026-05-04', 'value' => 123.0]],
    ])
        ->and($document?->status)->toBe(DocumentStatus::Issued)
        ->and($document?->document_id)->toBe(7001)
        ->and($document?->document_number)->toBe('GR/8')
        ->and($document?->issued_at?->toDateTimeString())->toBe('2026-05-04 10:00:00')
        ->and($document?->customer_id)->toBe(301)
        ->and($document?->customer_number)->toBe('GR26-C1')
        ->and($document?->attempts)->toBe(1)
        ->and($document?->error_type)->toBeNull();
    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/documents/getOne/') && $request['document_id'] === 7001);
    Event::assertDispatched(DocumentIssued::class, fn (DocumentIssued $event) => $event->document->is($document) && $event->document->status === DocumentStatus::Issued);
    Event::assertNotDispatched(DocumentFailed::class);
});

it('sends zero-rate lines without taxes and with their exemption reason', function () {
    fakeMoloniIssuing(['*/documents/getOne/*' => moloniDocumentRow(['net_value' => 223])]);
    Order::$invoiceDataUsing = fn (Order $order) => new InvoiceData(
        new CustomerData('C'.$order->id, $order->customer_vat, 'Acme Lda', null, null, null, null),
        [new InvoiceLine('Consulting', 1, '100', 23), new InvoiceLine('Training', 2, '50', 0, 'M07', summary: 'Two sessions')],
    );

    $document = Moloni::invoiceReceipt(Order::create(['customer_vat' => '509999999']), irPayment(22300))->fresh();

    expect(moloniRequests('invoiceReceipts/insert')[0]['products'][1])->toBe([
        'product_id' => 900,
        'name' => 'Training',
        'summary' => 'Two sessions',
        'qty' => 2.0,
        'price' => '50',
        'taxes' => [],
        'exemption_reason' => 'M07',
    ])
        ->and($document?->status)->toBe(DocumentStatus::Issued)
        ->and($document?->error_type)->toBeNull();
});

it('returns the existing live document instead of creating a second one', function () {
    Queue::fake();
    $order = Order::create(['customer_vat' => '509999999']);
    $first = Moloni::invoiceReceipt($order, irPayment());

    $again = Moloni::invoiceReceipt($order, irPayment());
    $invoice = Moloni::invoice($order);

    expect($again->is($first))->toBeTrue()
        ->and($invoice->is($first))->toBeTrue()
        ->and(InvoiceDocument::count())->toBe(1);
    Queue::assertPushed(IssueDocument::class, 1);
});

it('refuses a request it cannot issue without creating a row', function (array $settings, ?Closure $invoiceData, PaymentData $payment, string $message) {
    Http::fake();
    Queue::fake();
    useMoloniSettings($settings);
    Order::$invoiceDataUsing = $invoiceData;

    $e = catchMoloniException(fn () => Moloni::invoiceReceipt(Order::create(['customer_vat' => '509999999']), $payment));

    expect($e->type)->toBe(FailureType::Validation)
        ->and($e->getMessage())->toContain($message)
        ->and(InvoiceDocument::count())->toBe(0);
    Http::assertNothingSent();
    Queue::assertNothingPushed();
})->with([
    'no company' => [['company_id' => null], null, irPayment(), 'No Moloni company'],
    'no document set' => [['document_sets' => ['invoice' => 41]], null, irPayment(), 'No document set'],
    'another currency' => [[], fn (Order $order) => new InvoiceData(
        new CustomerData('C1', null, 'Acme Lda', null, null, null, null),
        [new InvoiceLine('Consulting', 1, '100', 23)],
        currency: 'USD',
    ), irPayment(), 'currency not supported yet'],
    'unknown payment method' => [[], null, irPayment(method: 'cash'), 'payment method'],
    'payment below the total' => [[], null, irPayment(12299), 'payment'],
]);

it('marks the document unknown when the insert response is lost and does not retry', function () {
    fakeMoloniIssuing(['*/invoiceReceipts/insert/*' => Http::response('Bad gateway', 502)]);
    $document = irPending();

    $job = runIssueJob($document);

    $document->refresh();
    expect($document->status)->toBe(DocumentStatus::Unknown)
        ->and($document->error_type)->toBe(FailureType::Unknown->value)
        ->and($document->error_message)->toContain('invoiceReceipts/insert')
        ->and($document->document_id)->toBeNull()
        ->and($document->attempts)->toBe(1)
        ->and(moloniRequests('invoiceReceipts/insert'))->toHaveCount(1);
    $job->assertNotReleased();
    $job->assertNotFailed();
    Event::assertDispatched(DocumentFailed::class);
    Event::assertNotDispatched(DocumentIssued::class);
});

it('fails without retrying when Moloni rejects the document', function () {
    fakeMoloniIssuing(['*/invoiceReceipts/insert/*' => [[['code' => '1 exemption_reason', 'description' => "Field 'exemption_reason' is required"]]]]);
    $document = irPending();

    $job = runIssueJob($document);

    $document->refresh();
    expect($document->status)->toBe(DocumentStatus::Failed)
        ->and($document->error_type)->toBe(FailureType::Validation->value)
        ->and($document->error_message)->toContain("Field 'exemption_reason' is required")
        ->and($document->active_key)->toBeNull();
    $job->assertNotReleased();
    Event::assertDispatched(DocumentFailed::class);
});

it('retries transient failures with backoff and fails after the last attempt', function () {
    fakeMoloniIssuing(['*/taxes/getAll/*' => Http::response('Unavailable', 503)]);
    $document = irPending();
    $job = new IssueDocument($document);

    expect($job->tries)->toBe(3)
        ->and($job->backoff)->toBe([60, 300, 900])
        ->and($job->timeout)->toBe(120)
        ->and($job->uniqueId())->toBe((string) $document->id);

    // The row counts the runs: the queue's own attempt number (here already 3) does not decide.
    foreach ([1, 2] as $run) {
        $e = catchMoloniException(fn () => runIssueJob($document, attempt: 3));
        expect($e->type)->toBe(FailureType::Transient)
            ->and($document->fresh()?->status)->toBe(DocumentStatus::Pending)
            ->and($document->fresh()?->attempts)->toBe($run);
    }
    Event::assertNotDispatched(DocumentFailed::class);

    runIssueJob($document);

    $document->refresh();
    expect($document->status)->toBe(DocumentStatus::Failed)
        ->and($document->error_type)->toBe(FailureType::Transient->value)
        ->and($document->attempts)->toBe(3)
        ->and(moloniRequests('invoiceReceipts/insert'))->toHaveCount(0);
    Event::assertDispatched(DocumentFailed::class);
});

it('keeps a sync-dispatched document pending when its first attempt fails transiently', function () {
    fakeMoloniIssuing(['*/taxes/getAll/*' => Http::response('Unavailable', 503)]);
    $order = Order::create(['customer_vat' => '509999999']);

    $e = catchMoloniException(fn () => Moloni::invoiceReceipt($order, irPayment()));

    $document = InvoiceDocument::sole();
    expect($e->type)->toBe(FailureType::Transient)
        ->and($document->status)->toBe(DocumentStatus::Pending)
        ->and($document->attempts)->toBe(1)
        ->and(moloniRequests('invoiceReceipts/insert'))->toHaveCount(0);
    Event::assertNotDispatched(DocumentFailed::class);
});

it('retries an uncertain customer write instead of marking the document unknown', function () {
    fakeMoloniIssuing([
        '*/customers/getByVat/*' => Http::response([]),
        '*/countries/getAll/*' => [['country_id' => 1, 'iso_3166_1' => 'PT']],
        '*/languages/getAll/*' => [['language_id' => 1, 'code' => 'PT']],
        '*/customers/insert/*' => Http::response('Gateway timeout', 504),
    ]);
    $document = irPending();

    $e = catchMoloniException(fn () => runIssueJob($document));

    expect($e->type)->toBe(FailureType::Unknown)
        ->and($document->fresh()?->status)->toBe(DocumentStatus::Pending)
        ->and(moloniRequests('invoiceReceipts/insert'))->toHaveCount(0);
});

it('fails before the insert when the line rates do not match Moloni\'s rates', function () {
    fakeMoloniIssuing();
    Order::$invoiceDataUsing = fn (Order $order) => new InvoiceData(
        new CustomerData('C'.$order->id, $order->customer_vat, 'Acme Lda', null, null, null, null),
        [new InvoiceLine('Consulting', 1, '100000', 23.0004)],
    );
    Queue::fake();
    $document = Moloni::invoiceReceipt(Order::create(['customer_vat' => '509999999']), irPayment(12300040));

    runIssueJob($document);

    $document->refresh();
    expect($document->status)->toBe(DocumentStatus::Failed)
        ->and($document->error_type)->toBe(FailureType::Validation->value)
        ->and($document->error_message)->toContain("line tax rates must match Moloni's rates exactly")
        ->and(moloniRequests('invoiceReceipts/insert'))->toHaveCount(0);
});

it('keeps the document issued and flags total_mismatch when Moloni computes another total', function () {
    fakeMoloniIssuing(['*/documents/getOne/*' => moloniDocumentRow(['net_value' => 123.01])]);

    $document = Moloni::invoiceReceipt(Order::create(['customer_vat' => '509999999']), irPayment())->fresh();

    expect($document?->status)->toBe(DocumentStatus::Issued)
        ->and($document?->document_id)->toBe(7001)
        ->and($document?->error_type)->toBe('total_mismatch')
        ->and($document?->error_message)->toContain('123.01');
    Event::assertDispatched(DocumentIssued::class);
});

it('keeps the document issued when the read-back fails', function (mixed $readBack) {
    fakeMoloniIssuing(['*/documents/getOne/*' => $readBack]);

    $document = Moloni::invoiceReceipt(Order::create(['customer_vat' => '509999999']), irPayment())->fresh();

    expect($document?->status)->toBe(DocumentStatus::Issued)
        ->and($document?->document_id)->toBe(7001)
        ->and($document?->document_number)->toBeNull()
        ->and($document?->error_type)->toBe('readback_failed')
        ->and(moloniRequests('invoiceReceipts/insert'))->toHaveCount(1);
    Event::assertDispatched(DocumentIssued::class);
})->with([
    'server error' => [fn () => Http::response('Unavailable', 503)],
    'another document' => [fn () => moloniDocumentRow(['document_id' => 1])],
    'not found' => [fn () => Http::response([])],
]);

it('marks the document unknown when Moloni confirms it without an id', function () {
    fakeMoloniIssuing(['*/invoiceReceipts/insert/*' => ['valid' => 1]]);

    $document = Moloni::invoiceReceipt(Order::create(['customer_vat' => '509999999']), irPayment())->fresh();

    expect($document?->status)->toBe(DocumentStatus::Unknown)
        ->and($document?->document_id)->toBeNull()
        ->and(moloniRequests('invoiceReceipts/insert'))->toHaveCount(1);
    Event::assertDispatched(DocumentFailed::class);
    Event::assertNotDispatched(DocumentIssued::class);
});

/**
 * Replaces the document client with one whose insert throws $error without sending anything.
 */
function irInsertThrows(Throwable $error): void
{
    app()->instance(MoloniClient::class, new class(app(MoloniConnector::class), $error) extends MoloniClient
    {
        public function __construct(MoloniConnector $connector, private readonly Throwable $error)
        {
            parent::__construct($connector);
        }

        public function insert(DocumentType $type, array $payload): int
        {
            throw $this->error;
        }
    });
}

it('marks the document unknown when the insert fails in an unexpected way', function () {
    fakeMoloniIssuing();
    $document = irPending();
    irInsertThrows(new RuntimeException('Socket closed with the URL in the message.'));

    runIssueJob($document);

    $document->refresh();
    expect($document->status)->toBe(DocumentStatus::Unknown)
        ->and($document->error_type)->toBe(FailureType::Unknown->value)
        ->and($document->error_message)->toBe('The document may have been issued: RuntimeException.');
    Event::assertDispatched(DocumentFailed::class);
});

it('puts the document back to pending when the token request fails before the insert', function () {
    fakeMoloniIssuing();
    $document = irPending();
    irInsertThrows(new MoloniException('Could not log in to Moloni.', FailureType::Transient));

    $e = catchMoloniException(fn () => runIssueJob($document));

    $document->refresh();
    expect($e->type)->toBe(FailureType::Transient)
        ->and($document->status)->toBe(DocumentStatus::Pending)
        ->and($document->attempts)->toBe(1)
        ->and(moloniRequests('invoiceReceipts/insert'))->toHaveCount(0);
    Event::assertNotDispatched(DocumentFailed::class);
});

it('does nothing for a document that is already settled', function (DocumentStatus $status) {
    Http::fake();
    $document = irPending();
    $document->status = $status;
    $document->save();

    runIssueJob($document);

    Http::assertNothingSent();
    expect($document->fresh()?->status)->toBe($status);
})->with([DocumentStatus::Issued, DocumentStatus::Failed, DocumentStatus::Superseded, DocumentStatus::Voided]);

it('finishes a document whose id was saved before the job stopped, without inserting again', function () {
    fakeMoloniIssuing();
    $document = irPending();
    $document->fill(['status' => DocumentStatus::Issuing, 'company_id' => 5, 'document_id' => 7001])->save();

    runIssueJob($document);

    $document->refresh();
    expect($document->status)->toBe(DocumentStatus::Issued)
        ->and($document->document_number)->toBe('GR/8')
        ->and(moloniRequests('invoiceReceipts/insert'))->toHaveCount(0)
        ->and(moloniRequests('invoiceReceipts/getAll'))->toHaveCount(0);
});

it('leaves the document to the run that holds its lock, without spending a try', function () {
    Http::fake();
    $document = irPending();
    $lock = Cache::lock("moloni-doc-{$document->id}", 150);
    $lock->get();

    $job = runIssueJob($document);

    $job->assertNotReleased();
    $job->assertNotFailed();
    Http::assertNothingSent();
    expect($document->fresh()?->status)->toBe(DocumentStatus::Pending)
        ->and($document->fresh()?->attempts)->toBe(0);
    $lock->release();
});

it('settles a document only as far as is safe when the job itself fails', function (DocumentStatus $status, int $attempts, DocumentStatus $expected) {
    $document = irPending();
    $document->fill(['status' => $status, 'attempts' => $attempts])->save();

    (new IssueDocument($document))->failed(new RuntimeException('Worker timed out.'));

    $document->refresh();
    expect($document->status)->toBe($expected);

    if ($expected !== $status) {
        expect($document->error_type)->toBe('job_failed')
            ->and($document->error_message)->toBe('The issuing job failed: RuntimeException.');
        Event::assertDispatched(DocumentFailed::class);
    } else {
        Event::assertNotDispatched(DocumentFailed::class);
    }
})->with([
    'pending with tries left' => [DocumentStatus::Pending, 1, DocumentStatus::Pending],
    'pending on its last try' => [DocumentStatus::Pending, 3, DocumentStatus::Failed],
    'issuing' => [DocumentStatus::Issuing, 1, DocumentStatus::Unknown],
    'unknown' => [DocumentStatus::Unknown, 1, DocumentStatus::Unknown],
]);

it('leaves the document alone when the job fails while another run holds it', function () {
    $document = irPending();
    $document->fill(['status' => DocumentStatus::Issuing, 'attempts' => 3])->save();
    $lock = Cache::lock("moloni-doc-{$document->id}", 150);
    $lock->get();

    (new IssueDocument($document))->failed(new RuntimeException('Worker timed out.'));

    expect($document->fresh()?->status)->toBe(DocumentStatus::Issuing);
    $lock->release();
});

it('never inserts when the row changed while the payload was being built', function () {
    fakeMoloniIssuing();
    $document = irPending();
    app()->bind(CustomerResolver::class, fn () => new class(app(MoloniConnector::class), app(MoloniCatalog::class), app(MoloniSettings::class), $document) extends CustomerResolver
    {
        public function __construct(MoloniConnector $connector, MoloniCatalog $catalog, MoloniSettings $settings, private readonly InvoiceDocument $document)
        {
            parent::__construct($connector, $catalog, $settings);
        }

        public function resolve(CustomerData $customer): ResolvedCustomer
        {
            // Something outside this run moves the row on.
            $row = InvoiceDocument::findOrFail($this->document->id);
            $row->status = DocumentStatus::Superseded;
            $row->save();

            return parent::resolve($customer);
        }
    });

    runIssueJob($document);

    expect($document->fresh()?->status)->toBe(DocumentStatus::Superseded)
        ->and(moloniRequests('invoiceReceipts/insert'))->toHaveCount(0);
});

it('dates the payment in Portugal\'s calendar, whatever the application timezone', function (string $appTimezone, string $paidAt, string $expected) {
    config(['app.timezone' => $appTimezone]);
    travelTo(Carbon::parse('2026-07-02 10:00:00', 'UTC'));
    fakeMoloniIssuing();

    Moloni::invoiceReceipt(Order::create(['customer_vat' => '509999999']), new PaymentData('card', Carbon::parse($paidAt), 12300));

    expect(moloniRequests('invoiceReceipts/insert')[0]['payments'][0]['date'])->toBe($expected);
})->with([
    // 23:30 UTC in July is 00:30 of the next day in Lisbon.
    'UTC, paid late in the evening' => ['UTC', '2026-07-01 23:30:00+00:00', '2026-07-02'],
    // 00:30 in Madrid is still the previous day in Lisbon.
    'east of Lisbon, paid just after midnight' => ['Europe/Madrid', '2026-07-02 00:30:00+02:00', '2026-07-01'],
]);

it('dates the due date on the day the document is issued', function () {
    fakeMoloniIssuing(['*/invoices/insert/*' => ['valid' => 1, 'document_id' => 7001], '*/documents/getOne/*' => moloniDocumentRow(['document_set_id' => 41])]);
    Queue::fake();
    $document = Moloni::invoice(Order::create(['customer_vat' => '509999999']), 30);
    travelTo(Carbon::parse('2026-05-06 10:00:00'));

    runIssueJob($document);

    expect($document->fresh()?->due_at?->toDateString())->toBe('2026-06-05')
        ->and(moloniRequests('invoices/insert')[0]['date'])->toBe('2026-05-06')
        ->and(moloniRequests('invoices/insert')[0]['expiration_date'])->toBe('2026-06-05');
});

it('completes a document whose read-back failed, without inserting or announcing it again', function () {
    fakeMoloniIssuing();
    $document = irPending();
    $document->fill(['status' => DocumentStatus::Issued, 'document_id' => 7001, 'issued_at' => now(), 'error_type' => 'readback_failed', 'error_message' => 'Lost.'])->save();

    runIssueJob($document);

    $document->refresh();
    expect($document->status)->toBe(DocumentStatus::Issued)
        ->and($document->document_number)->toBe('GR/8')
        ->and($document->error_type)->toBeNull()
        ->and(moloniRequests('invoiceReceipts/insert'))->toHaveCount(0)
        ->and(moloniRequests('invoiceReceipts/getAll'))->toHaveCount(0);
    Event::assertNotDispatched(DocumentIssued::class);
});

it('never moves an issued document whose read-back failed out of issued when the company changed', function () {
    Http::fake();
    $document = irPending();
    $document->fill(['status' => DocumentStatus::Issued, 'document_id' => 7001, 'issued_at' => now(), 'error_type' => 'readback_failed'])->save();
    useMoloniSettings(['company_id' => 6]);

    // Skipped while another company is configured, and still repaired later.
    runIssueJob($document);

    expect($document->fresh()?->status)->toBe(DocumentStatus::Issued)
        ->and($document->fresh()?->document_id)->toBe(7001)
        ->and($document->fresh()?->error_type)->toBe('readback_failed');
    Http::assertNothingSent();
});

it('retries a failed document on the same row', function () {
    $document = irPending();
    $document->fill(['status' => DocumentStatus::Failed, 'error_type' => 'validation', 'error_message' => 'Rejected.'])->save();
    Queue::fake();

    Moloni::retry($document);

    $document->refresh();
    expect($document->status)->toBe(DocumentStatus::Pending)
        ->and($document->error_type)->toBeNull()
        ->and($document->error_message)->toBeNull()
        ->and(InvoiceDocument::count())->toBe(1);
    Queue::assertPushed(IssueDocument::class, fn (IssueDocument $job) => $job->document->is($document));
});

it('retries only failed documents that do not collide with a live one', function () {
    $order = Order::create(['customer_vat' => '509999999']);
    $failed = InvoiceDocument::factory()->invoice()->failed()->for($order, 'invoiceable')->create();
    $pending = irPending($order);

    expect(catchMoloniException(fn () => Moloni::retry($pending))->type)->toBe(FailureType::Validation)
        ->and(catchMoloniException(fn () => Moloni::retry($failed))->type)->toBe(FailureType::Validation)
        ->and($failed->fresh()?->status)->toBe(DocumentStatus::Failed);
});

it('retries from the stored status, not from a stale copy', function () {
    $document = irPending();
    $stale = $document->replicate();
    $stale->id = $document->id;
    $stale->exists = true;
    $stale->status = DocumentStatus::Failed;

    expect(catchMoloniException(fn () => Moloni::retry($stale))->type)->toBe(FailureType::Validation)
        ->and($document->fresh()?->status)->toBe(DocumentStatus::Pending);
    Queue::assertPushed(IssueDocument::class, 1);
});

it('refuses to retry or reissue a document another run holds', function (DocumentStatus $status, Closure $action) {
    $document = irPending();
    $document->fill(['status' => $status, 'error_type' => 'validation'])->save();
    $lock = Cache::lock("moloni-doc-{$document->id}", 150);
    $lock->get();

    $e = catchMoloniException(fn () => $action($document));

    expect($e->type)->toBe(FailureType::Validation)
        ->and($e->getMessage())->toContain('being processed')
        ->and($document->fresh()?->status)->toBe($status)
        ->and(InvoiceDocument::count())->toBe(1);
    Queue::assertPushed(IssueDocument::class, 1);
    $lock->release();
})->with([
    'retry' => [DocumentStatus::Failed, fn (InvoiceDocument $document) => Moloni::retry($document)],
    'reissue' => [DocumentStatus::Unknown, fn (InvoiceDocument $document) => Moloni::reissue($document)],
]);

it('resets the attempts when a failed document is retried', function () {
    $document = irPending();
    $document->fill(['status' => DocumentStatus::Failed, 'attempts' => 3])->save();

    Moloni::retry($document);

    expect($document->attempts)->toBe(0)
        ->and($document->status)->toBe(DocumentStatus::Pending);
});

it('reissue supersedes the old document and frees the live slot', function () {
    fakeMoloniIssuing();
    $order = Order::create(['customer_vat' => '509999999']);
    $old = Moloni::invoiceReceipt($order, irPayment())->refresh();
    expect($old->status)->toBe(DocumentStatus::Issued);
    Queue::fake();
    Order::$invoiceDataUsing = fn (Order $order) => new InvoiceData(
        new CustomerData('C'.$order->id, '501111111', 'Acme Lda', null, null, null, null),
        [new InvoiceLine('Consulting', 1, '100', 23)],
    );

    $new = Moloni::reissue($old);

    $old->refresh();
    expect($old->status)->toBe(DocumentStatus::Superseded)
        ->and($old->active_key)->toBeNull()
        ->and($old->document_id)->toBe(7001)
        ->and($new->status)->toBe(DocumentStatus::Pending)
        ->and($new->type)->toBe(DocumentType::InvoiceReceipt)
        ->and($new->active_key)->toBe(InvoiceDocument::activeKeyFor($order))
        ->and($new->operation_key)->toBe(InvoiceDocument::operationKey(DocumentType::InvoiceReceipt, $order, 2))
        ->and($new->reference)->toBe('#ORDER-1')
        ->and($new->customer_vat)->toBe('501111111')
        ->and(PaymentData::fromArray($new->payload['payment'] ?? [])->toArray())->toBe(irPayment()->toArray());
    Queue::assertPushed(IssueDocument::class, fn (IssueDocument $job) => $job->document->is($new));
});

it('reissues only issued or unknown documents', function (DocumentStatus $status) {
    $document = irPending();
    $document->status = $status;
    $document->save();

    expect(catchMoloniException(fn () => Moloni::reissue($document))->type)->toBe(FailureType::Validation)
        ->and($document->fresh()?->status)->toBe($status)
        ->and(InvoiceDocument::count())->toBe(1);
})->with([DocumentStatus::Pending, DocumentStatus::Issuing, DocumentStatus::Failed, DocumentStatus::Superseded]);

it('asks Moloni for the PDF link every time and never stores it', function () {
    fakeMoloniIssuing(['*/documents/getPDFLink/*' => Http::sequence()
        ->push(['url' => 'https://moloni.test/downloads/?h=one'])
        ->push(['url' => 'https://moloni.test/downloads/?h=two'])
        ->push(['valid' => 0]),
    ]);
    $document = Moloni::invoiceReceipt(Order::create(['customer_vat' => '509999999']), irPayment())->fresh();
    assert($document instanceof InvoiceDocument);
    $before = $document->getAttributes();

    expect(Moloni::pdfLink($document))->toBe('https://moloni.test/downloads/?h=one')
        ->and(Moloni::pdfLink($document))->toBe('https://moloni.test/downloads/?h=two')
        ->and(catchMoloniException(fn () => Moloni::pdfLink($document))->type)->toBe(FailureType::Validation)
        ->and($document->fresh()?->getAttributes())->toBe($before)
        ->and(json_encode(InvoiceDocument::first()?->getAttributes()))->not->toContain('moloni.test');
    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/documents/getPDFLink/') && $request['document_id'] === 7001);
});

it('asks for a PDF link only for issued documents', function () {
    Http::fake();
    $document = irPending();

    expect(catchMoloniException(fn () => Moloni::pdfLink($document))->type)->toBe(FailureType::Validation);
    Http::assertNothingSent();
});

it('resolves the manager from the facade as a singleton', function () {
    expect(Moloni::getFacadeRoot())->toBe(app(Noop\Moloni\Moloni::class))
        ->and(app(Noop\Moloni\Moloni::class))->toBe(app(Noop\Moloni\Moloni::class));
});

it('never issues a document in another company than the one it was requested for', function (DocumentStatus $status, DocumentStatus $expected) {
    Http::fake();
    $document = irPending();
    $document->status = $status;
    $document->save();
    useMoloniSettings(['company_id' => 6]);

    runIssueJob($document);

    $document->refresh();
    expect($document->status)->toBe($expected)
        ->and($document->error_type)->toBe('company_changed');
    Http::assertNothingSent();
    Event::assertDispatched(DocumentFailed::class);
})->with([
    'pending' => [DocumentStatus::Pending, DocumentStatus::Failed],
    'issuing' => [DocumentStatus::Issuing, DocumentStatus::Unknown],
    'unknown' => [DocumentStatus::Unknown, DocumentStatus::Unknown],
]);

it('leaves an issuing document that already has its Moloni id to be finished by read-back', function () {
    fakeMoloniIssuing();
    $document = irPending();
    $document->fill(['status' => DocumentStatus::Issuing, 'document_id' => 7001, 'attempts' => 3])->save();

    (new IssueDocument($document))->failed(new RuntimeException('Worker timed out.'));

    expect($document->fresh()?->status)->toBe(DocumentStatus::Issuing)
        ->and($document->fresh()?->error_type)->toBeNull();
    Event::assertNotDispatched(DocumentFailed::class);

    runIssueJob($document);

    $document->refresh();
    expect($document->status)->toBe(DocumentStatus::Issued)
        ->and($document->document_number)->toBe('GR/8')
        ->and(moloniRequests('invoiceReceipts/insert'))->toHaveCount(0);
});

/**
 * Runs $during while the job resolves the customer, i.e. after the reconciliation and before the insert.
 */
function irDuringPayload(Closure $during): void
{
    app()->bind(CustomerResolver::class, fn () => new class(app(MoloniConnector::class), app(MoloniCatalog::class), app(MoloniSettings::class), $during) extends CustomerResolver
    {
        public function __construct(MoloniConnector $connector, MoloniCatalog $catalog, MoloniSettings $settings, private readonly Closure $during)
        {
            parent::__construct($connector, $catalog, $settings);
        }

        public function resolve(CustomerData $customer): ResolvedCustomer
        {
            ($this->during)();

            return parent::resolve($customer);
        }
    });
}

it('never inserts when the run took longer than its time budget', function () {
    fakeMoloniIssuing();
    $document = irPending();
    irDuringPayload(fn () => travelTo(now()->addSeconds(111)));

    runIssueJob($document);

    expect($document->fresh()?->status)->toBe(DocumentStatus::Issuing)
        ->and($document->fresh()?->document_id)->toBeNull()
        ->and($document->fresh()?->insert_sent_at)->toBeNull()
        ->and(moloniRequests('invoiceReceipts/insert'))->toHaveCount(0);
    Event::assertNotDispatched(DocumentFailed::class);
});

it('inserts when the run stayed within its time budget', function () {
    fakeMoloniIssuing();
    $document = irPending();
    irDuringPayload(fn () => travelTo(now()->addSeconds(100)));

    runIssueJob($document);

    expect($document->fresh()?->status)->toBe(DocumentStatus::Issued)
        ->and(moloniRequests('invoiceReceipts/insert'))->toHaveCount(1);
});

it('never inserts when the run no longer owns the document lock', function () {
    fakeMoloniIssuing();
    $document = irPending();
    $thief = Cache::lock("moloni-doc-{$document->id}", 150);
    irDuringPayload(function () use ($document, $thief) {
        Cache::lock("moloni-doc-{$document->id}")->forceRelease();
        $thief->get();
    });

    runIssueJob($document);

    expect($document->fresh()?->status)->toBe(DocumentStatus::Issuing)
        ->and($document->fresh()?->insert_sent_at)->toBeNull()
        ->and(moloniRequests('invoiceReceipts/insert'))->toHaveCount(0)
        // The job's release left the other run's lock in place.
        ->and(Cache::lock("moloni-doc-{$document->id}", 150)->get())->toBeFalse();
    $thief->release();
});

it('keeps repairing a read-back that fails, whatever the failure, within a day of issuing', function (mixed $readBack) {
    fakeMoloniIssuing(['*/documents/getOne/*' => $readBack]);
    $document = irPending();
    $document->fill(['status' => DocumentStatus::Issued, 'document_id' => 7001, 'issued_at' => now(), 'error_type' => 'readback_failed', 'attempts' => 1])->save();
    travelTo(now()->addHours(23));

    runIssueJob($document);
    runIssueJob($document);

    $document->refresh();
    expect($document->status)->toBe(DocumentStatus::Issued)
        ->and($document->document_id)->toBe(7001)
        ->and($document->error_type)->toBe('readback_failed')
        ->and(moloniRequests('documents/getOne'))->toHaveCount(2)
        ->and(moloniRequests('invoiceReceipts/insert'))->toHaveCount(0);
    Event::assertNotDispatched(DocumentIssued::class);
})->with([
    'another document' => [fn () => moloniDocumentRow(['document_id' => 1])],
    'not found' => [fn () => Http::response([])],
    'server error' => [fn () => Http::response('Unavailable', 503)],
]);

it('abandons the read-back repair a day after issuing, without reading', function (int $company) {
    Http::fake();
    $document = irPending();
    $document->fill(['status' => DocumentStatus::Issued, 'document_id' => 7001, 'issued_at' => now(), 'error_type' => 'readback_failed'])->save();
    useMoloniSettings(['company_id' => $company]);
    travelTo(now()->addHours(24));

    runIssueJob($document);

    $document->refresh();
    expect($document->status)->toBe(DocumentStatus::Issued)
        ->and($document->document_id)->toBe(7001)
        ->and($document->error_type)->toBe('readback_abandoned');
    Http::assertNothingSent();
})->with(['same company' => [5], 'another company' => [6]]);
