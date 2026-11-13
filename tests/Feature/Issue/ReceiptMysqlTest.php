<?php

/*
 * Two real MySQL/InnoDB transactions (REPEATABLE READ) interleaved, which SQLite cannot show: a
 * receipt requested inside a host transaction whose snapshot predates a receipt another
 * transaction committed must still be refused for the money that receipt takes; gap-lock
 * deadlocks between receipts on unrelated invoices are retried at top level and reach the caller
 * inside a host transaction; a same-key call committed meanwhile is returned. The second client
 * of the last three runs in a forked process (pcntl and posix required).
 *
 * Skipped unless MOLONI_TEST_MYSQL_DATABASE is set. It drops and recreates every table of that
 * database, so point it at a dedicated, empty one:
 *
 *   mysql -uroot -e 'create database laravel_moloni_test'
 *   MOLONI_TEST_MYSQL_DATABASE=laravel_moloni_test MOLONI_TEST_MYSQL_HOST=127.0.0.1 \
 *   MOLONI_TEST_MYSQL_PORT=3306 MOLONI_TEST_MYSQL_USERNAME=root MOLONI_TEST_MYSQL_PASSWORD= \
 *   vendor/bin/pest tests/Feature/Issue/ReceiptMysqlTest.php
 */

use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
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

const RMY_FORK_SKIP = 'The pcntl and posix extensions are needed to run a second MySQL client in a forked process.';

/**
 * Whether this PHP can fork the second client of the deadlock and same-key tests (see rmyFork()).
 */
function rmyCanFork(): bool
{
    return function_exists('pcntl_fork') && function_exists('pcntl_waitpid')
        && function_exists('posix_kill') && function_exists('posix_getpid');
}

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

/**
 * Runs $work in a forked process on its own PDO connection (never the parent's), as a second
 * client. Returns a closure that waits for it and gives back what $work returned. The child is
 * killed instead of exiting, so no destructor touches the connections it inherited.
 *
 * @param  Closure(PDO, Closure(): void): string  $work  Gets the connection and a callback that signals "ready".
 * @return array{0: Closure(): string, 1: Closure(): bool} Wait for the result; whether it signalled.
 */
function rmyFork(Closure $work): array
{
    $result = tempnam(sys_get_temp_dir(), 'rmy');
    $ready = $result.'.ready';
    $pid = pcntl_fork();

    if ($pid === 0) {
        try {
            $pdo = new PDO(
                'mysql:host='.rmyEnv('HOST', '127.0.0.1').';port='.rmyEnv('PORT', '3306').';dbname='.rmyEnv('DATABASE'),
                rmyEnv('USERNAME', 'root'),
                rmyEnv('PASSWORD'),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );
            $outcome = $work($pdo, function () use ($ready): void {
                touch($ready);
            });
        } catch (Throwable $e) {
            $outcome = 'error: '.$e->getMessage();
        }

        file_put_contents($result, $outcome);
        posix_kill(posix_getpid(), SIGKILL);
    }

    $deadline = microtime(true) + 10;

    return [
        function () use ($pid, $result, $ready): string {
            pcntl_waitpid($pid, $status);
            $outcome = (string) file_get_contents($result);
            @unlink($result);
            @unlink($ready);

            return $outcome;
        },
        function () use ($ready, $deadline): bool {
            while (! file_exists($ready) && microtime(true) < $deadline) {
                usleep(20_000);
            }

            return file_exists($ready);
        },
    ];
}

/**
 * The second client of the deadlock tests: a transaction made heavier than the package's (so
 * InnoDB picks the package's as the victim), which locks $invoiceId and reads its settling links
 * with FOR UPDATE (gap locks, as the package does), signals, then inserts a link for it.
 */
function rmyGapLocker(int $invoiceId): Closure
{
    return function (PDO $pdo, Closure $ready) use ($invoiceId): string {
        $pdo->beginTransaction();

        for ($i = 0; $i < 20; $i++) {
            $pdo->exec("insert into invoice_documents (type, operation_key, status, reference, currency, expected_total_cents, attempts) values ('receipt', 'other:{$invoiceId}:{$i}', 'failed', '#OTHER', 'EUR', 1, 0)");
        }

        $receiptId = (int) $pdo->lastInsertId();
        ($pdo->query("select id from invoice_documents where id = {$invoiceId} for update") ?: throw new RuntimeException('Lock failed.'))->fetchAll();
        ($pdo->query("select l.associated_document_id from invoice_document_links l join invoice_documents s on s.id = l.document_id where l.associated_document_id in ({$invoiceId}) for update") ?: throw new RuntimeException('Read failed.'))->fetchAll();
        $ready();
        usleep(1_500_000);

        try {
            $pdo->exec("insert into invoice_document_links (document_id, associated_document_id, amount_cents) values ({$receiptId}, {$invoiceId}, 1)");
            $pdo->commit();

            return 'committed';
        } catch (PDOException $e) {
            return str_contains($e->getMessage(), 'Deadlock') ? 'deadlock' : 'error: '.$e->getMessage();
        }
    };
}

/**
 * @return array{0: InvoiceDocument, 1: InvoiceDocument} Two issued invoices without any link.
 */
function rmyTwoInvoices(): array
{
    $invoice = fn (int $documentId, int $customerId) => InvoiceDocument::factory()->invoice()->issued()->create([
        'company_id' => 5, 'document_id' => $documentId, 'document_number' => 'GR/'.$documentId,
        'expected_total_cents' => 5000, 'customer_id' => $customerId, 'customer_vat' => '50999999'.$customerId % 10,
    ]);

    return [$invoice(8001, 301), $invoice(8002, 302)];
}

/**
 * Asserts no invoice is settled beyond its total by live receipts.
 */
function rmyNoOverAllocation(): void
{
    foreach (InvoiceDocument::where('type', DocumentType::Invoice)->get() as $invoice) {
        expect(InvoiceDocument::outstandingCentsForUpdate([$invoice])[$invoice->id])->toBeGreaterThanOrEqual(0);
        $settled = DB::table('invoice_document_links as l')->join('invoice_documents as s', 's.id', '=', 'l.document_id')
            ->where('l.associated_document_id', $invoice->id)->where('s.type', 'receipt')
            ->whereIn('s.status', ['pending', 'issuing', 'issued', 'unknown'])->sum('l.amount_cents');
        expect((int) $settled)->toBeLessThanOrEqual($invoice->expected_total_cents);
    }
}

it('retries its own transaction when receipts on unrelated invoices deadlock on gap locks', function () {
    [$a, $b] = rmyTwoInvoices();
    $tries = 0;
    InvoiceDocument::creating(function (InvoiceDocument $document) use (&$tries) {
        $tries += $document->type === DocumentType::Receipt ? 1 : 0;
    });
    [$wait, $ready] = rmyFork(rmyGapLocker($a->id));
    expect($ready())->toBeTrue();

    $receipt = Moloni::receipt(Order::create(), [[$b, 5000]], new PaymentData('transfer', Carbon::now(), 5000));

    $other = $wait();
    // InnoDB rolls back the lighter transaction, the package's, which retries it once the other committed.
    expect($other)->toBe('committed')
        ->and($tries)->toBe(2)
        ->and($receipt->fresh()?->status)->toBe(DocumentStatus::Pending)
        ->and($receipt->links()->sole()->associated_document_id)->toBe($b->id);
    rmyNoOverAllocation();
})->skip(rmyEnv('DATABASE') === '', RMY_SKIP)->skip(! rmyCanFork(), RMY_FORK_SKIP);

it('lets the deadlock reach the caller inside a host transaction, without a receipt', function () {
    [$a, $b] = rmyTwoInvoices();
    [$wait, $ready] = rmyFork(rmyGapLocker($a->id));
    expect($ready())->toBeTrue();
    $caught = null;

    try {
        DB::transaction(fn () => Moloni::receipt(Order::create(), [[$b, 5000]], new PaymentData('transfer', Carbon::now(), 5000)));
    } catch (DeadlockException|QueryException $e) {
        $caught = $e;
    }

    // Not retried inside the host transaction, which the database already rolled back.
    expect($wait())->toBe('committed')
        ->and($caught)->toBeInstanceOf(DeadlockException::class)
        ->and($caught?->getMessage())->toContain('Deadlock')
        ->and(InvoiceDocument::where('type', DocumentType::Receipt)->where('status', 'pending')->count())->toBe(0)
        ->and(DB::transactionLevel())->toBe(0);
    rmyNoOverAllocation();
})->skip(rmyEnv('DATABASE') === '', RMY_SKIP)->skip(! rmyCanFork(), RMY_FORK_SKIP);

it('returns the receipt another transaction committed under the same key while this one waited', function () {
    $invoice = InvoiceDocument::factory()->invoice()->issued()->create([
        'company_id' => 5, 'document_id' => 8001, 'document_number' => 'GR/8001',
        'expected_total_cents' => 12300, 'customer_id' => 301, 'customer_vat' => '509999999',
    ]);
    $owner = Order::create();
    $payment = new PaymentData('transfer', Carbon::now(), 12300);
    $operationKey = InvoiceDocument::operationKey(DocumentType::Receipt, $owner, 'payment-1');
    $payload = json_encode(['payment' => $payment->toArray(), 'allocations' => [['invoice_document_id' => $invoice->id, 'moloni_document_id' => 8001, 'amount_cents' => 12300]]]);
    // The other call: records the same request under the key, and commits a little later.
    [$wait, $ready] = rmyFork(function (PDO $pdo, Closure $ready) use ($operationKey, $payload, $invoice, $owner): string {
        $pdo->beginTransaction();
        $insert = $pdo->prepare("insert into invoice_documents (invoiceable_type, invoiceable_id, type, operation_key, status, company_id, document_set_id, reference, currency, expected_total_cents, customer_id, payload, attempts) values (?, ?, 'receipt', ?, 'pending', 5, 43, '#RC-OTHER', 'EUR', 12300, 301, ?, 0)");
        $insert->execute([$owner->getMorphClass(), $owner->id, $operationKey, $payload]);
        $receiptId = (int) $pdo->lastInsertId();
        $pdo->exec("insert into invoice_document_links (document_id, associated_document_id, amount_cents) values ({$receiptId}, {$invoice->id}, 12300)");
        $ready();
        usleep(1_000_000);
        $pdo->commit();

        return (string) $receiptId;
    });
    expect($ready())->toBeTrue();

    $receipt = Moloni::receipt($owner, [[$invoice, 12300]], $payment, 'payment-1');

    expect($receipt->id)->toBe((int) $wait())
        ->and(InvoiceDocument::where('type', DocumentType::Receipt)->count())->toBe(1);
    Queue::assertNothingPushed();
    rmyNoOverAllocation();
})->skip(rmyEnv('DATABASE') === '', RMY_SKIP)->skip(! rmyCanFork(), RMY_FORK_SKIP);
