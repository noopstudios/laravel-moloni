<?php

/*
 * Locking reads on real MySQL/InnoDB (REPEATABLE READ), which SQLite compiles away: which reads
 * take row locks, and which must, to see a row another transaction committed after the host
 * transaction took its snapshot.
 *
 * Skipped unless MOLONI_TEST_MYSQL_DATABASE is set. It drops and recreates every table of that
 * database, so point it at a dedicated, empty one (see ReceiptMysqlTest.php for the variables):
 *
 *   MOLONI_TEST_MYSQL_DATABASE=laravel_moloni_test vendor/bin/pest tests/Feature/Issue/LockingMysqlTest.php
 */

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Noop\Moloni\Enums\DocumentStatus;
use Noop\Moloni\Enums\DocumentType;
use Noop\Moloni\Events\DocumentFailed;
use Noop\Moloni\Events\DocumentIssued;
use Noop\Moloni\Events\DocumentVoided;
use Noop\Moloni\Facades\Moloni;
use Noop\Moloni\Models\InvoiceDocument;
use Noop\Moloni\Models\InvoiceDocumentLink;
use Noop\Moloni\Tests\Fixtures\Order;

use function Pest\Laravel\travelTo;

/**
 * An environment variable of the MySQL test setup (read with getenv: the package has no .env).
 */
function lmyEnv(string $name, string $default = ''): string
{
    $value = getenv('MOLONI_TEST_MYSQL_'.$name);

    return is_string($value) && $value !== '' ? $value : $default;
}

const LMY_SKIP = 'Set MOLONI_TEST_MYSQL_DATABASE (see ReceiptMysqlTest.php) to run the MySQL locking tests.';

beforeEach(function () {
    $database = lmyEnv('DATABASE');

    if ($database === '') {
        return;
    }

    $connection = [
        'driver' => 'mysql',
        'host' => lmyEnv('HOST', '127.0.0.1'),
        'port' => lmyEnv('PORT', '3306'),
        'database' => $database,
        'username' => lmyEnv('USERNAME', 'root'),
        'password' => lmyEnv('PASSWORD'),
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
    Queue::fake();
    useIssueSettings(['document_sets' => ['invoice' => 41, 'invoice_receipt' => 42, 'receipt' => 43, 'credit_note' => 44, 'proforma' => 45]]);
    travelTo(Carbon::parse('2026-05-04 10:00:00'));
    Event::fake([DocumentIssued::class, DocumentFailed::class, DocumentVoided::class]);
});

afterEach(function () {
    if (lmyEnv('DATABASE') !== '') {
        DB::setDefaultConnection('testing');
    }
});

/**
 * Runs $call on the host connection T2 inside a transaction whose snapshot was taken before
 * $meanwhile committed on T1; the host transaction is rolled back afterwards.
 *
 * @template T
 *
 * @param  Closure(): T  $call
 * @return T
 */
function lmyInStaleTransaction(Closure $meanwhile, Closure $call): mixed
{
    // T2 (the host's transaction) starts and reads: REPEATABLE READ fixes its snapshot here.
    $t2 = DB::connection('mysql_t2');
    $t2->beginTransaction();
    $t2->table('invoice_documents')->count();

    $meanwhile();

    DB::setDefaultConnection('mysql_t2');

    try {
        return $call();
    } finally {
        $t2->rollBack();
        DB::setDefaultConnection('mysql_t1');
    }
}

it('reads the dependants of a document it cancels without locking them', function () {
    Http::fake(['*/grant/*' => moloniGrant(), '*/documents/documentCancel/*' => ['valid' => 1]]);
    $invoice = InvoiceDocument::factory()->invoice()->issued()->create(['company_id' => 5, 'document_id' => 8001]);
    // A failed receipt on it: read by the join, rejected by the status filter.
    $receipt = InvoiceDocument::factory()->create(['type' => DocumentType::Receipt, 'status' => DocumentStatus::Failed, 'company_id' => 5]);
    InvoiceDocumentLink::create(['document_id' => $receipt->id, 'associated_document_id' => $invoice->id, 'amount_cents' => 100]);
    $joins = [];
    DB::connection('mysql_t1')->listen(function (QueryExecuted $query) use (&$joins) {
        if (str_contains($query->sql, 'invoice_document_links')) {
            $joins[] = $query->sql;
        }
    });

    Moloni::cancel($invoice, 'Order cancelled');

    expect($invoice->status)->toBe(DocumentStatus::Voided)
        ->and($joins)->not->toBeEmpty()
        ->and(array_filter($joins, fn (string $sql) => str_contains(strtolower($sql), 'for update')))->toBe([]);
})->skip(lmyEnv('DATABASE') === '', LMY_SKIP);

it('returns the invoice another transaction committed after the host transaction took its snapshot', function () {
    $order = Order::create(['customer_vat' => '509999999']);
    $first = null;

    $again = lmyInStaleTransaction(
        function () use ($order, &$first) {
            $first = Moloni::invoice($order);
        },
        fn () => Moloni::invoice($order),
    );

    expect($again->id)->toBe($first?->id)
        ->and(InvoiceDocument::count())->toBe(1);
})->skip(lmyEnv('DATABASE') === '', LMY_SKIP);

it('returns the pro-forma another transaction committed under the same key after the host transaction took its snapshot', function () {
    $order = Order::create(['customer_vat' => '509999999']);
    $first = null;

    $again = lmyInStaleTransaction(
        function () use ($order, &$first) {
            $first = Moloni::proforma($order, 'quote-1');
        },
        fn () => Moloni::proforma($order, 'quote-1'),
    );

    expect($again->id)->toBe($first?->id)
        ->and(InvoiceDocument::count())->toBe(1);
})->skip(lmyEnv('DATABASE') === '', LMY_SKIP);
