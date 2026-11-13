<?php

/*
 * Two real MySQL/InnoDB transactions (REPEATABLE READ) interleaved, which SQLite cannot show: a
 * credit note requested (or retried) inside a host transaction whose snapshot predates a credit
 * note another transaction committed on the same document must still see it, so it neither
 * credits a line twice nor takes the same "/NC{n}" reference.
 *
 * Skipped unless MOLONI_TEST_MYSQL_DATABASE is set. It drops and recreates every table of that
 * database, so point it at a dedicated, empty one (see ReceiptMysqlTest.php for the variables):
 *
 *   MOLONI_TEST_MYSQL_DATABASE=laravel_moloni_test vendor/bin/pest tests/Feature/Issue/CreditNoteMysqlTest.php
 */

use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Noop\Moloni\Data\CreditLine;
use Noop\Moloni\Data\CustomerData;
use Noop\Moloni\Data\InvoiceData;
use Noop\Moloni\Data\InvoiceLine;
use Noop\Moloni\Enums\DocumentStatus;
use Noop\Moloni\Enums\DocumentType;
use Noop\Moloni\Events\DocumentFailed;
use Noop\Moloni\Events\DocumentIssued;
use Noop\Moloni\Facades\Moloni;
use Noop\Moloni\Models\InvoiceDocument;

use function Pest\Laravel\travelTo;

/**
 * An environment variable of the MySQL test setup (read with getenv: the package has no .env).
 */
function cnmyEnv(string $name, string $default = ''): string
{
    $value = getenv('MOLONI_TEST_MYSQL_'.$name);

    return is_string($value) && $value !== '' ? $value : $default;
}

const CNMY_SKIP = 'Set MOLONI_TEST_MYSQL_DATABASE (see ReceiptMysqlTest.php) to run the MySQL interleaving tests.';

beforeEach(function () {
    $database = cnmyEnv('DATABASE');

    if ($database === '') {
        return;
    }

    $connection = [
        'driver' => 'mysql',
        'host' => cnmyEnv('HOST', '127.0.0.1'),
        'port' => cnmyEnv('PORT', '3306'),
        'database' => $database,
        'username' => cnmyEnv('USERNAME', 'root'),
        'password' => cnmyEnv('PASSWORD'),
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => '',
    ];
    // Two connections, so two concurrent transactions.
    config(['database.connections.mysql_t1' => $connection, 'database.connections.mysql_t2' => $connection]);
    DB::setDefaultConnection('mysql_t1');

    Schema::dropAllTables();
    /** @var callable(): void $createSettingsTable */
    $createSettingsTable = [require __DIR__.'/../../../vendor/spatie/laravel-settings/database/migrations/create_settings_table.php.stub', 'up'];
    $createSettingsTable();
    Artisan::call('migrate', ['--database' => 'mysql_t1', '--realpath' => true, '--path' => [
        realpath(__DIR__.'/../../../database/migrations'),
        realpath(__DIR__.'/../../../database/settings'),
        realpath(__DIR__.'/../../database/migrations'),
    ]]);

    Http::preventStrayRequests();
    Http::fake();
    Queue::fake();
    useIssueSettings(['document_sets' => ['invoice' => 41, 'invoice_receipt' => 42, 'receipt' => 43, 'credit_note' => 44]]);
    travelTo(Carbon::parse('2026-05-04 10:00:00'));
    Event::fake([DocumentIssued::class, DocumentFailed::class]);
});

afterEach(function () {
    if (cnmyEnv('DATABASE') !== '') {
        DB::setDefaultConnection('testing');
    }
});

/**
 * An issued invoice or invoice-receipt (Moloni id 8001): 3 × "Consulting" at 10.00 and 2 ×
 * "Support" at 5.00, both at 23% (36.90 + 12.30 = 49.20).
 */
function cnmyOrigin(DocumentType $type = DocumentType::Invoice): InvoiceDocument
{
    return InvoiceDocument::factory()->issued()->create([
        'type' => $type, 'company_id' => 5, 'document_id' => 8001, 'document_number' => 'GR/8001', 'reference' => '#ORDER-1',
        'expected_total_cents' => 4920, 'customer_id' => 301, 'customer_vat' => '509999999',
        'payload' => ['invoice' => (new InvoiceData(
            new CustomerData('C1', '509999999', 'Acme Lda', null, null, null, null),
            [new InvoiceLine('Consulting', 3, '10', 23), new InvoiceLine('Support', 2, '5', 23)],
        ))->toArray(), 'payment' => null, 'due_in_days' => 0],
    ]);
}

/**
 * Starts the host's transaction on the second connection and reads: REPEATABLE READ fixes its
 * snapshot here, before the first connection commits anything else.
 */
function cnmyHostTransaction(int $links): Connection
{
    $t2 = DB::connection('mysql_t2');
    $t2->beginTransaction();
    expect($t2->table('invoice_document_links')->count())->toBe($links)
        ->and($t2->table('invoice_documents')->count())->toBe($links + 1);

    return $t2;
}

it('refuses to credit again what another transaction credited after this one took its snapshot', function () {
    $origin = cnmyOrigin();
    $t2 = cnmyHostTransaction(0);

    // T1 commits a credit note for everything.
    $first = Moloni::creditNote($origin);

    DB::setDefaultConnection('mysql_t2');

    try {
        expect(fn () => Moloni::creditNote($origin))->toThrow(InvalidArgumentException::class, 'Nothing is left')
            ->and(fn () => Moloni::creditNote($origin, [new CreditLine(1, 1)]))->toThrow(InvalidArgumentException::class, 'at most 0');
    } finally {
        $t2->rollBack();
        DB::setDefaultConnection('mysql_t1');
    }

    expect(InvoiceDocument::where('type', DocumentType::CreditNote)->sole()->is($first))->toBeTrue();
    Http::assertNothingSent();
})->skip(cnmyEnv('DATABASE') === '', CNMY_SKIP);

it('numbers a credit note after the one another transaction committed after this one took its snapshot', function () {
    $origin = cnmyOrigin();
    $t2 = cnmyHostTransaction(0);

    // T1 commits a credit note for the first line.
    $first = Moloni::creditNote($origin, [new CreditLine(0, 3)]);

    DB::setDefaultConnection('mysql_t2');

    try {
        $second = Moloni::creditNote($origin, [new CreditLine(1, 2)]);
        $reference = $second->reference;
        $claim = $second->links()->sole()->amount_cents;
    } finally {
        $t2->rollBack();
        DB::setDefaultConnection('mysql_t1');
    }

    expect($first->reference)->toBe('#ORDER-1/NC1')
        ->and($reference)->toBe('#ORDER-1/NC2')
        // What the invoice still owed once the first credit note claimed its 36.90.
        ->and($claim)->toBe(1230);
})->skip(cnmyEnv('DATABASE') === '', CNMY_SKIP);

it('refuses to retry a failed credit note for lines another transaction credited after this one took its snapshot', function () {
    // An invoice-receipt: nothing is open on it, so only the credited quantities can refuse the retry.
    $origin = cnmyOrigin(DocumentType::InvoiceReceipt);
    $failed = Moloni::creditNote($origin, [new CreditLine(0, 3)]);
    $failed->fill(['status' => DocumentStatus::Failed])->save();
    $t2 = cnmyHostTransaction(1);

    // T1 commits another credit note for the same line, which the failed one no longer holds.
    Moloni::creditNote($origin, [new CreditLine(0, 3)]);

    DB::setDefaultConnection('mysql_t2');

    try {
        expect(catchMoloniException(fn () => Moloni::retry($failed))->getMessage())->toContain('no longer fits')->toContain('at most 0');
    } finally {
        $t2->rollBack();
        DB::setDefaultConnection('mysql_t1');
    }

    expect($failed->fresh()?->status)->toBe(DocumentStatus::Failed);
})->skip(cnmyEnv('DATABASE') === '', CNMY_SKIP);
