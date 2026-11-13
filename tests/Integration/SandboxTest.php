<?php

/*
 * The package against the real Moloni API, in a Moloni test company. Excluded from the default
 * run; run it with:
 *
 *     MOLONI_SANDBOX_ENV=/path/to/.env.sandbox vendor/bin/pest --group=sandbox
 *
 * The env file (default: .env.sandbox in the repository root, git-ignored) holds the Moloni
 * credentials plus MOLONI_SANDBOX_COMPANY_ID and MOLONI_SANDBOX_DOCUMENT_SET_ID; see
 * .env.sandbox.example. Without the file every test is skipped.
 *
 * Every run issues real, irreversible fiscal documents (about ten) in that company and series,
 * dated today, for a fictitious customer. Use a company and a series kept for tests only: never
 * a company that communicates with the tax authority, never Moloni's shared demo company, and
 * never a series that receives future-dated documents (they lock it). Run one test at a time
 * (--filter) while working on it.
 */

use Dotenv\Dotenv;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Noop\Moloni\Catalog\MoloniCatalog;
use Noop\Moloni\Data\CreditLine;
use Noop\Moloni\Data\CustomerData;
use Noop\Moloni\Data\InvoiceData;
use Noop\Moloni\Data\InvoiceLine;
use Noop\Moloni\Data\PaymentData;
use Noop\Moloni\Enums\DocumentStatus;
use Noop\Moloni\Enums\DocumentType;
use Noop\Moloni\Enums\FailureType;
use Noop\Moloni\Exceptions\MoloniException;
use Noop\Moloni\Facades\Moloni;
use Noop\Moloni\Http\MoloniConnector;
use Noop\Moloni\Http\TokenStore;
use Noop\Moloni\Models\InvoiceDocument;
use Noop\Moloni\MoloniClient;
use Noop\Moloni\Resolvers\CustomerResolver;
use Noop\Moloni\Settings\MoloniSettings;
use Noop\Moloni\Tests\Fixtures\Order;
use PHPUnit\Framework\Assert;

/** Moloni's shared public demo company: anything written there is visible to strangers. */
const SANDBOX_FORBIDDEN_COMPANY_ID = 5;

/** A fictitious NIF (its check digit is wrong, so it is nobody's) for the documents' customer. */
const SANDBOX_VAT = '100000000';

/** The customer key of the documents' customer, created on the first run and reused after. */
const SANDBOX_CUSTOMER_KEY = 'DOCS';

/** The service product every line uses, created on the first run and reused after. */
const SANDBOX_PRODUCT_REFERENCE = 'SBX-SERVICE';

beforeEach(function () {
    $env = sandboxEnvironment();
    $companyId = $env['company_id'];
    $documentSetId = $env['document_set_id'];

    config([
        'moloni.client_id' => $env['client_id'],
        'moloni.client_secret' => $env['client_secret'],
        'moloni.email' => $env['email'],
        'moloni.password' => $env['password'],
        'moloni.prefix' => 'SBX',
        'moloni.customer_updates' => 'own',
        'moloni.queue_connection' => 'sync',
        'app.timezone' => 'Europe/Lisbon',
    ]);

    // Last line of defence: no write leaves for another company or series.
    Event::listen(RequestSending::class, fn (RequestSending $event) => sandboxGuard($event, $companyId, $documentSetId));

    useMoloniSettings(['company_id' => $companyId]);
    $catalog = app(MoloniCatalog::class);

    if (! $catalog->documentSets()->contains(fn (array $row) => (int) ($row['document_set_id'] ?? 0) === $documentSetId)) {
        Assert::fail('MOLONI_SANDBOX_DOCUMENT_SET_ID is not a document set of MOLONI_SANDBOX_COMPANY_ID.');
    }

    $maturityToday = $catalog->maturityDates()->first(fn (array $row) => (int) ($row['days'] ?? -1) === 0)
        ?? Assert::fail('The sandbox company has no maturity date of 0 days.');
    $paymentMethod = $catalog->paymentMethods()->first() ?? Assert::fail('The sandbox company has no payment method.');
    $category = $catalog->productCategories()->first() ?? Assert::fail('The sandbox company has no product category.');
    $unit = $catalog->measurementUnits()->first() ?? Assert::fail('The sandbox company has no measurement unit.');

    useMoloniSettings([
        'company_id' => $companyId,
        'document_sets' => array_fill_keys(array_map(fn (DocumentType $type) => $type->value, DocumentType::cases()), $documentSetId),
        'payment_methods' => ['transfer' => (int) $paymentMethod['payment_method_id']],
        'maturity_dates' => [0 => (int) $maturityToday['maturity_date_id']],
        'default_category_id' => (int) $category['category_id'],
        'default_unit_id' => (int) $unit['unit_id'],
        'default_product_id' => null,
        'tax_overrides' => [],
        'fiscal_zone' => 'PT',
    ]);

    // Every reference is new, so no document of an earlier run or test is ever reconciled into this one.
    $run = now()->format('ymdHis').'-'.Str::lower(Str::random(4));
    Order::$referenceUsing = fn (Order $order) => "SBX-{$run}-{$order->id}";
    Order::$invoiceDataUsing = fn () => sandboxInvoice();
});

it('logs in and reads the catalog', function () {
    expect(app(TokenStore::class)->accessToken() !== '')->toBeTrue();

    $catalog = app(MoloniCatalog::class);
    $companyId = app(MoloniSettings::class)->companyIdOrNull();

    expect($catalog->companies()->contains(fn (array $row) => (int) ($row['company_id'] ?? 0) === $companyId))->toBeTrue()
        ->and($catalog->paymentMethods())->not->toBeEmpty()
        ->and($catalog->countryId('PT'))->toBeGreaterThan(0)
        ->and($catalog->languageIdFor('PT'))->toBeGreaterThan(0)
        ->and($catalog->hasExemption('M07'))->toBeTrue()
        ->and($catalog->taxes()->contains(fn (array $row) => (float) ($row['value'] ?? 0) === 23.0 && ($row['fiscal_zone'] ?? null) === 'PT'))->toBeTrue();
});

it('creates then updates a customer by VAT', function () {
    $key = 'C'.now()->format('ymdHis');
    $vat = sandboxFreshVat();
    $resolver = app(CustomerResolver::class);

    $created = $resolver->resolve(new CustomerData($key, $vat, 'Cliente Teste Criado', null, 'Rua de Teste 1', '1000-001', 'Lisboa', 'PT', true));
    $stored = sandboxCustomerByVat($vat);

    expect($stored['customer_id'])->toBe($created->id)
        ->and($stored['number'])->toBe($created->number)
        ->and($stored['name'])->toBe('Cliente Teste Criado');

    $updated = $resolver->resolve(new CustomerData($key, $vat, 'Cliente Teste Atualizado', null, 'Rua de Teste 2', '1000-002', 'Lisboa', 'PT', true));
    $stored = sandboxCustomerByVat($vat);

    expect($updated->id)->toBe($created->id)
        ->and($stored['name'])->toBe('Cliente Teste Atualizado')
        ->and($stored['address'])->toBe('Rua de Teste 2')
        ->and($stored['zip_code'])->toBe('1000-002');
});

it('issues an invoice-receipt and fetches its PDF link', function () {
    $document = sandboxIssued(Moloni::invoiceReceipt(sandboxOrder(), sandboxPayment(sandboxInvoice()->totalCents())));
    $row = app(MoloniClient::class)->document((int) $document->document_id);

    expect(MoloniClient::cents($row['net_value']))->toBe(4730)
        ->and(MoloniClient::cents($row['gross_value']))->toBe(3939)
        ->and(MoloniClient::cents($row['reconciled_value']))->toBe(4730)
        ->and((int) $row['document_set_id'])->toBe($document->document_set_id)
        ->and($row['our_reference'])->toBe($document->reference)
        ->and($row['entity_vat'])->toBe(SANDBOX_VAT);

    $url = Moloni::pdfLink($document);

    // Never stored: Moloni's links expire.
    expect($url)->toStartWith('https://')
        ->and(sandboxHolds($document->fresh()?->getAttributes() ?? [], $url))->toBeFalse();
});

it('issues an invoice, then one receipt for two invoices', function () {
    $first = sandboxIssued(Moloni::invoice(sandboxOrder()));
    $second = sandboxIssued(Moloni::invoice(sandboxOrder()));

    expect($first->expected_total_cents)->toBe(4730)
        ->and($first->customer_id)->toBe($second->customer_id);

    // The first invoice in full, 10.00 of the second.
    $receipt = sandboxIssued(Moloni::receipt($first, [[$first, 4730], [$second, 1000]], sandboxPayment(5730), 'payment-'.$first->reference));
    $client = app(MoloniClient::class);
    $row = $client->document((int) $receipt->document_id);
    $settled = collect(sandboxRows($row['associated_documents']))->mapWithKeys(fn (array $entry) => [(int) $entry['associated_id'] => MoloniClient::cents($entry['value'])]);

    expect(MoloniClient::cents($row['net_value']))->toBe(5730)
        ->and($settled->all())->toEqual([$first->document_id => 4730, $second->document_id => 1000])
        ->and(MoloniClient::cents($client->document((int) $first->document_id)['reconciled_value']))->toBe(4730)
        ->and(MoloniClient::cents($client->document((int) $second->document_id)['reconciled_value']))->toBe(1000)
        ->and($first->fresh()?->outstandingCents())->toBe(0)
        ->and($second->fresh()?->outstandingCents())->toBe(3730);
});

it('issues a pro-forma and converts it', function () {
    $proforma = sandboxIssued(Moloni::proforma(sandboxOrder(), 'quote-1'));
    $conversion = sandboxIssued(Moloni::convert($proforma, sandboxPayment(4730)));
    $client = app(MoloniClient::class);
    $row = $client->document((int) $conversion->document_id);

    expect($conversion->type)->toBe(DocumentType::InvoiceReceipt)
        ->and($conversion->reference)->toBe($proforma->reference)
        ->and(collect(sandboxRows($row['associated_documents']))->pluck('associated_id')->map(fn ($id) => (int) $id)->all())->toBe([$proforma->document_id])
        ->and(MoloniClient::cents($client->document((int) $proforma->document_id)['reconciled_value']))->toBe(4730);
});

it('issues a partial credit note', function () {
    $client = app(MoloniClient::class);
    $origin = sandboxIssued(Moloni::invoiceReceipt(sandboxOrder(), sandboxPayment(4730)));

    // The lines as documents/getOne returns them, in the shapes the credit note payload relies on.
    $lines = $client->document((int) $origin->document_id)['products'];
    expect($lines)->toBeList()->toHaveCount(3);

    foreach (sandboxInvoice()->lines as $index => $requested) {
        $line = $lines[$index];

        expect($line['name'])->toBe($requested->name)
            ->and($line['document_product_id'])->toBeNumeric()
            ->and($line['product_id'])->toBeNumeric()
            ->and((float) $line['qty'])->toBe($requested->quantity)
            ->and((string) (float) $line['price'])->toBe((string) (float) $requested->unitNetPrice);

        if ($requested->isZeroRated()) {
            expect($line['taxes'])->toBe([])->and($line['exemption_reason'])->toBe('M07');
        } else {
            // The tax value is the rate, sent back as such on the credit note's line.
            expect($line['taxes'])->toHaveCount(1)
                ->and($line['taxes'][0]['tax_id'])->toBeNumeric()
                ->and((float) $line['taxes'][0]['value'])->toBe(23.0);
        }
    }

    // One of the three units of the first line: 8.13 + 1.87 VAT.
    $credit = sandboxIssued(Moloni::creditNote($origin, [new CreditLine(0, 1)]));
    $row = $client->document((int) $credit->document_id);

    expect(MoloniClient::cents($row['net_value']))->toBe(1000)
        ->and((int) $row['document_type_id'])->toBe(MoloniClient::CREDIT_NOTE_TYPE_ID)
        ->and((int) $row['products'][0]['related_id'])->toBe((int) $lines[0]['document_product_id'])
        ->and((int) $row['associated_documents'][0]['associated_id'])->toBe($origin->document_id);

    // Reconciliation finds credit notes on documents/getAll by our_reference.
    $found = $client->findByReference(DocumentType::CreditNote, $credit->reference);

    expect($found)->toHaveCount(1)
        ->and((int) $found[0]['document_id'])->toBe($credit->document_id)
        ->and((int) $found[0]['document_type_id'])->toBe(MoloniClient::CREDIT_NOTE_TYPE_ID);

    // The insert landed but its answer was lost: reconcile adopts it and inserts nothing.
    $documentId = $credit->document_id;
    $credit->forceFill([
        'status' => DocumentStatus::Unknown,
        'document_id' => null,
        'document_number' => null,
        'issued_at' => null,
        'insert_sent_at' => now(),
        'error_type' => FailureType::Unknown->value,
        'error_message' => 'The answer to the insert was lost.',
    ])->save();

    Moloni::reconcile($credit);
    $credit->refresh();
    $found = $client->findByReference(DocumentType::CreditNote, $credit->reference);

    expect($credit->status)->toBe(DocumentStatus::Issued)
        ->and($credit->document_id)->toBe($documentId)
        ->and($found)->toHaveCount(1)
        ->and((int) $found[0]['document_id'])->toBe($documentId);
});

it('cancels a fresh document or reports why Moloni refused', function () {
    $document = sandboxIssued(Moloni::invoiceReceipt(sandboxOrder(), sandboxPayment(4730)));

    try {
        Moloni::cancel($document, 'Teste automático do pacote');
    } catch (MoloniException $e) {
        sandboxNote('Moloni refused to cancel a fresh invoice-receipt: '.$e->getMessage());

        expect($e->type)->toBe(FailureType::Validation)
            ->and($e->getMessage())->not->toBe('')
            ->and($document->fresh()?->status)->toBe(DocumentStatus::Issued);

        return;
    }

    expect($document->status)->toBe(DocumentStatus::Voided)
        ->and((int) app(MoloniClient::class)->document((int) $document->document_id)['status'])->toBe(2);
});

it('fails a receipt and a credit note on a document cancelled in Moloni', function () {
    $client = app(MoloniClient::class);
    $invoice = sandboxIssued(Moloni::invoice(sandboxOrder()));

    // Cancelled in Moloni behind the package's back: locally it is still issued.
    $client->cancel((int) $invoice->document_id, 'Teste automático do pacote');
    expect((int) $client->document((int) $invoice->document_id)['status'])->toBe(2);

    $receipt = Moloni::receipt($invoice, [[$invoice, 4730]], sandboxPayment(4730))->fresh();
    $credit = Moloni::creditNote($invoice, [new CreditLine(0, 1)])->fresh();

    sandboxNote('Receipt on a cancelled invoice: '.$receipt?->error_message);
    sandboxNote('Credit note on a cancelled invoice: '.$credit?->error_message);

    expect($receipt?->status)->toBe(DocumentStatus::Failed)
        ->and($receipt?->error_type)->toBe(FailureType::Validation->value)
        ->and($receipt?->document_id)->toBeNull()
        ->and($credit?->status)->toBe(DocumentStatus::Failed)
        ->and($credit?->error_type)->toBe(FailureType::Validation->value)
        ->and($credit?->document_id)->toBeNull();
});

/**
 * The sandbox settings from the env file, read without touching the process environment. Skips
 * without the file; refuses (fails) when it is incomplete or names Moloni's demo company.
 *
 * @return array{client_id: string, client_secret: string, email: string, password: string, company_id: int, document_set_id: int}
 */
function sandboxEnvironment(): array
{
    $configured = getenv('MOLONI_SANDBOX_ENV');
    $path = is_string($configured) && $configured !== '' ? $configured : dirname(__DIR__, 2).'/.env.sandbox';

    if (! is_file($path)) {
        Assert::markTestSkipped('No Moloni sandbox env file (set MOLONI_SANDBOX_ENV or create .env.sandbox).');
    }

    $values = Dotenv::parse((string) file_get_contents($path));
    $value = fn (string $name) => is_string($values[$name] ?? null) ? trim($values[$name]) : '';

    foreach (['MOLONI_CLIENT_ID', 'MOLONI_CLIENT_SECRET', 'MOLONI_EMAIL', 'MOLONI_PASSWORD', 'MOLONI_SANDBOX_COMPANY_ID', 'MOLONI_SANDBOX_DOCUMENT_SET_ID'] as $name) {
        if ($value($name) === '') {
            Assert::fail("The sandbox env file has no {$name}: refusing to call Moloni.");
        }
    }

    $companyId = $value('MOLONI_SANDBOX_COMPANY_ID');
    $documentSetId = $value('MOLONI_SANDBOX_DOCUMENT_SET_ID');

    if (! ctype_digit($companyId) || ! ctype_digit($documentSetId) || (int) $companyId === 0 || (int) $documentSetId === 0) {
        Assert::fail('MOLONI_SANDBOX_COMPANY_ID and MOLONI_SANDBOX_DOCUMENT_SET_ID must be positive integers.');
    }

    if ((int) $companyId === SANDBOX_FORBIDDEN_COMPANY_ID) {
        Assert::fail("Company {$companyId} is Moloni's shared demo company: the sandbox suite never writes there.");
    }

    return [
        'client_id' => $value('MOLONI_CLIENT_ID'),
        'client_secret' => $value('MOLONI_CLIENT_SECRET'),
        'email' => $value('MOLONI_EMAIL'),
        'password' => $value('MOLONI_PASSWORD'),
        'company_id' => (int) $companyId,
        'document_set_id' => (int) $documentSetId,
    ];
}

/**
 * Stops any request that would write outside the sandbox company and series: every call other
 * than a get or count must carry the sandbox company, and a document set, when sent, must be the
 * sandbox series.
 */
function sandboxGuard(RequestSending $event, int $companyId, int $documentSetId): void
{
    $path = (string) parse_url($event->request->url(), PHP_URL_PATH);

    if (str_contains($path, '/grant/')) {
        return;
    }

    $method = basename($path);
    $data = $event->request->data();
    $isRead = str_starts_with($method, 'get') || str_starts_with($method, 'count');

    if (array_key_exists('company_id', $data) && (int) $data['company_id'] !== $companyId) {
        throw new RuntimeException("Blocked a sandbox request to {$path} for another company.");
    }

    if (! $isRead && ($data['company_id'] ?? null) !== $companyId) {
        throw new RuntimeException("Blocked a sandbox write to {$path} without the sandbox company.");
    }

    if (! $isRead && array_key_exists('document_set_id', $data) && (int) $data['document_set_id'] !== $documentSetId) {
        throw new RuntimeException("Blocked a sandbox write to {$path} for another document set.");
    }
}

/**
 * Three lines of the sandbox service: 3 × 8.13008 and 1 × 10.00 at 23%, and 1 × 5.00 exempt
 * (M07). Moloni's totals: 39.39 before VAT, 47.30 in all.
 */
function sandboxInvoice(): InvoiceData
{
    return new InvoiceData(
        new CustomerData(SANDBOX_CUSTOMER_KEY, SANDBOX_VAT, 'Cliente Teste Pacote', null, 'Rua de Teste 1', '1000-001', 'Lisboa', 'PT', true),
        [
            new InvoiceLine('Serviço de teste A', 3, '8.13008', 23, reference: SANDBOX_PRODUCT_REFERENCE),
            new InvoiceLine('Serviço de teste B', 1, '10', 23, reference: SANDBOX_PRODUCT_REFERENCE),
            new InvoiceLine('Serviço de teste isento', 1, '5', 0, 'M07', reference: SANDBOX_PRODUCT_REFERENCE),
        ],
    );
}

function sandboxOrder(): Order
{
    return Order::create(['customer_vat' => SANDBOX_VAT]);
}

function sandboxPayment(int $cents): PaymentData
{
    return new PaymentData('transfer', now(), $cents);
}

/**
 * The document as the synchronous job left it, which must be issued without any flag.
 */
function sandboxIssued(InvoiceDocument $document): InvoiceDocument
{
    $document->refresh();

    Assert::assertSame(DocumentStatus::Issued, $document->status, "The {$document->type->value} was not issued: {$document->error_type} {$document->error_message}");
    Assert::assertNull($document->error_type, "The {$document->type->value} was issued with a flag: {$document->error_type} {$document->error_message}");
    Assert::assertNotNull($document->document_id);

    return $document;
}

/**
 * The one customer with exactly this VAT number.
 *
 * @return array<string, mixed>
 */
function sandboxCustomerByVat(string $vat): array
{
    $rows = array_values(array_filter(
        app(MoloniConnector::class)->readAll('customers/getByVat', ['vat' => $vat]),
        fn (mixed $row) => is_array($row) && ($row['vat'] ?? null) === $vat,
    ));

    Assert::assertCount(1, $rows);

    return $rows[0];
}

/**
 * A NIF no customer of the company has yet, with a wrong check digit so it is nobody's.
 */
function sandboxFreshVat(): string
{
    $digits = '1'.str_pad((string) random_int(0, 9_999_999), 7, '0', STR_PAD_LEFT);
    $sum = 0;

    for ($i = 0; $i < 8; $i++) {
        $sum += (int) $digits[$i] * (9 - $i);
    }

    $check = 11 - $sum % 11;
    $check = $check >= 10 ? 0 : $check;

    return $digits.(($check + 1) % 10);
}

/**
 * Whether any value holds the text, also inside values stored as JSON (e.g. the payload).
 *
 * @param  array<array-key, mixed>  $values
 */
function sandboxHolds(array $values, string $text): bool
{
    foreach ($values as $value) {
        if (is_string($value)) {
            $decoded = json_decode($value, true);

            if (str_contains($value, $text) || (is_array($decoded) && sandboxHolds($decoded, $text))) {
                return true;
            }
        } elseif (is_array($value) && sandboxHolds($value, $text)) {
            return true;
        }
    }

    return false;
}

/**
 * The rows of a list Moloni returned.
 *
 * @return list<array<array-key, mixed>>
 */
function sandboxRows(mixed $rows): array
{
    Assert::assertIsArray($rows);

    return array_values(array_filter($rows, is_array(...)));
}

/**
 * What Moloni answered, for the record (no ids, no credentials).
 */
function sandboxNote(string $note): void
{
    fwrite(STDERR, "[sandbox] {$note}\n");
}
