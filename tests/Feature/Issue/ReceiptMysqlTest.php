<?php

/*
 * Two real MySQL/InnoDB transactions (REPEATABLE READ) interleaved, which SQLite cannot show: a
 * receipt requested inside a host transaction whose snapshot predates a receipt another
 * transaction committed must still be refused for the money that receipt takes.
 *
 * Skipped unless MOLONI_TEST_MYSQL_DATABASE is set. It drops and recreates every table of that
 * database, so point it at a dedicated, empty one:
 *
 *   mysql -uroot -e 'create database laravel_moloni_test'
 *   MOLONI_TEST_MYSQL_DATABASE=laravel_moloni_test MOLONI_TEST_MYSQL_HOST=127.0.0.1 \
 *   MOLONI_TEST_MYSQL_PORT=3306 MOLONI_TEST_MYSQL_USERNAME=root MOLONI_TEST_MYSQL_PASSWORD= \
 *   vendor/bin/pest tests/Feature/Issue/ReceiptMysqlTest.php
 */

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Noop\Moloni\Data\PaymentData;
use Noop\Moloni\Enums\DocumentStatus;
use Noop\Moloni\Enums\DocumentType;
use Noop\Moloni\Events\DocumentFailed;
use Noop\Moloni\Events\DocumentIssued;
use Noop\Moloni\Facades\Moloni;
use Noop\Moloni\Models\InvoiceDocument;
use Noop\Moloni\Tests\Fixtures\Order;

use function Pest\Laravel\travelTo;

/**
 * An environment variable of the MySQL test setup (read with getenv: the package has no .env).
 */
function rmyEnv(string $name, string $default = ''): string
{
    $value = getenv('MOLONI_TEST_MYSQL_'.$name);

    return is_string($value) && $value !== '' ? $value : $default;
}

const RMY_SKIP = 'Set MOLONI_TEST_MYSQL_DATABASE (see the file header) to run the MySQL interleaving tests.';

beforeEach(function () {
    $database = rmyEnv('DATABASE');

    if ($database === '') {
        return;
    }

    $connection = [
        'driver' => 'mysql',
        'host' => rmyEnv('HOST', '127.0.0.1'),
        'port' => rmyEnv('PORT', '3306'),
        'database' => $database,
        'username' => rmyEnv('USERNAME', 'root'),
        'password' => rmyEnv('PASSWORD'),
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
    useIssueSettings(['document_sets' => ['invoice' => 41, 'invoice_receipt' => 42, 'receipt' => 43]]);
    travelTo(Carbon::parse('2026-05-04 10:00:00'));
    Event::fake([DocumentIssued::class, DocumentFailed::class]);
});

afterEach(function () {
    if (rmyEnv('DATABASE') !== '') {
        DB::setDefaultConnection('testing');
    }
});

it('refuses a receipt for money another transaction committed after this one took its snapshot', function () {
    $invoice = InvoiceDocument::factory()->invoice()->issued()->create([
        'company_id' => 5, 'document_id' => 8001, 'document_number' => 'GR/8001',
        'expected_total_cents' => 12300, 'customer_id' => 301, 'customer_vat' => '509999999',
    ]);
    $payment = new PaymentData('transfer', Carbon::now(), 12300);
    $owner = Order::create();

    // T2 (the host's transaction) starts and reads: REPEATABLE READ fixes its snapshot here.
    $t2 = DB::connection('mysql_t2');
    $t2->beginTransaction();
    expect($t2->table('invoice_document_links')->count())->toBe(0);

    // T1 commits a pending receipt for the whole invoice.
    $first = Moloni::receipt($owner, [[$invoice, 12300]], $payment);

    // T2 asks for the same money inside its transaction.
    DB::setDefaultConnection('mysql_t2');

    try {
        expect(fn () => Moloni::receipt($owner, [[$invoice, 12300]], $payment))
            ->toThrow(InvalidArgumentException::class, 'still owes');
    } finally {
        $t2->rollBack();
        DB::setDefaultConnection('mysql_t1');
    }

    expect(InvoiceDocument::where('type', DocumentType::Receipt)->sole()->is($first))->toBeTrue()
        ->and($first->status)->toBe(DocumentStatus::Pending);
    Http::assertNothingSent();
})->skip(rmyEnv('DATABASE') === '', RMY_SKIP);

it('refuses to retry a failed receipt for money another transaction committed after this one took its snapshot', function () {
    $invoice = InvoiceDocument::factory()->invoice()->issued()->create([
        'company_id' => 5, 'document_id' => 8001, 'document_number' => 'GR/8001',
        'expected_total_cents' => 12300, 'customer_id' => 301, 'customer_vat' => '509999999',
    ]);
    $failed = Moloni::receipt(Order::create(), [[$invoice, 12300]], new PaymentData('transfer', Carbon::now(), 12300));
    $failed->fill(['status' => DocumentStatus::Failed])->save();

    $t2 = DB::connection('mysql_t2');
    $t2->beginTransaction();
    expect($t2->table('invoice_document_links')->count())->toBe(1);

    // T1 commits another pending receipt for the same money.
    Moloni::receipt(Order::create(), [[$invoice, 12300]], new PaymentData('transfer', Carbon::now(), 12300));

    DB::setDefaultConnection('mysql_t2');

    try {
        expect(catchMoloniException(fn () => Moloni::retry($failed))->getMessage())->toContain('still owe');
    } finally {
        $t2->rollBack();
        DB::setDefaultConnection('mysql_t1');
    }

    expect($failed->fresh()?->status)->toBe(DocumentStatus::Failed);
})->skip(rmyEnv('DATABASE') === '', RMY_SKIP);
