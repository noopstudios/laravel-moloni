<?php

use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Noop\Moloni\Data\CustomerData;
use Noop\Moloni\Data\InvoiceData;
use Noop\Moloni\Data\InvoiceLine;
use Noop\Moloni\Enums\DocumentStatus;
use Noop\Moloni\Enums\DocumentType;
use Noop\Moloni\Enums\FailureType;
use Noop\Moloni\Facades\Moloni;
use Noop\Moloni\Filament\Actions\CancelDocumentAction;
use Noop\Moloni\Filament\Actions\ConvertProformaAction;
use Noop\Moloni\Filament\Actions\CreditNoteAction;
use Noop\Moloni\Filament\Actions\DownloadPdfAction;
use Noop\Moloni\Filament\Actions\ReconcileDocumentAction;
use Noop\Moloni\Filament\Actions\ReissueDocumentAction;
use Noop\Moloni\Filament\Actions\RetryDocumentAction;
use Noop\Moloni\Filament\Pages\ManageMoloniSettings;
use Noop\Moloni\Filament\Resources\InvoiceDocuments\InvoiceDocumentResource;
use Noop\Moloni\Filament\Resources\InvoiceDocuments\Pages\ListInvoiceDocuments;
use Noop\Moloni\Filament\Resources\InvoiceDocuments\Pages\ViewInvoiceDocument;
use Noop\Moloni\Jobs\IssueDocument;
use Noop\Moloni\Models\InvoiceDocument;
use Noop\Moloni\Settings\MoloniSettings;
use Noop\Moloni\Tests\Fixtures\Order;
use Noop\Moloni\Tests\Fixtures\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    actingAs(User::create(['name' => 'Ana', 'email' => 'ana@example.test', 'password' => 'secret']));
});

function grantMoloniPermission(): void
{
    Gate::define('manage moloni', fn () => true);
}

/**
 * An issued invoice for a new order (Moloni company 5, id 8001, number GR/8, total 123.00).
 *
 * @param  array<string, mixed>  $attributes
 */
function issuedInvoice(array $attributes = []): InvoiceDocument
{
    $order = Order::create(['customer_vat' => '509999999']);

    return InvoiceDocument::factory()->issued()->create([
        'invoiceable_type' => $order->getMorphClass(),
        'invoiceable_id' => $order->id,
        'company_id' => 5,
        'document_set_id' => 41,
        'document_id' => 8001,
        'document_number' => 'GR/8',
        'reference' => '#ORDER-'.$order->id,
        'customer_id' => 301,
        'customer_vat' => '509999999',
        'payload' => ['invoice' => (new InvoiceData(
            new CustomerData('C1', '509999999', 'Acme Lda', null, null, null, null),
            [new InvoiceLine('Consulting', 1, '100', 23)],
        ))->toArray(), 'due_in_days' => 0],
        ...$attributes,
    ]);
}

it('forbids the settings page and the resource without the permission', function () {
    $document = InvoiceDocument::factory()->issued()->create();

    get(ManageMoloniSettings::getUrl())->assertForbidden();
    get(InvoiceDocumentResource::getUrl('index'))->assertForbidden();
    get(InvoiceDocumentResource::getUrl('view', ['record' => $document]))->assertForbidden();

    grantMoloniPermission();
    get(InvoiceDocumentResource::getUrl('index'))->assertOk()->assertSee($document->reference);

    // An unset permission grants nothing, whatever the Gate says about the default name.
    config(['moloni.permissions.manage' => null]);
    get(InvoiceDocumentResource::getUrl('index'))->assertForbidden();

    Gate::define('administer moloni', fn () => true);
    config(['moloni.permissions.full_admin' => 'administer moloni']);
    get(InvoiceDocumentResource::getUrl('view', ['record' => $document]))->assertOk();
});

it('lists the documents read-only, filtered by type and status', function () {
    grantMoloniPermission();
    $invoice = issuedInvoice();
    $failed = InvoiceDocument::factory()->failed()->create(['error_message' => 'Rejected by Moloni.']);
    $proforma = InvoiceDocument::factory()->proforma()->issued()->create();

    Livewire::test(ListInvoiceDocuments::class)
        ->assertCanSeeTableRecords([$invoice, $failed, $proforma])
        ->assertSee('Rejected by Moloni.')
        ->filterTable('type', DocumentType::Proforma->value)
        ->assertCanSeeTableRecords([$proforma])
        ->assertCanNotSeeTableRecords([$invoice, $failed])
        ->resetTableFilters()
        ->filterTable('status', DocumentStatus::Failed->value)
        ->assertCanSeeTableRecords([$failed])
        ->assertCanNotSeeTableRecords([$invoice, $proforma]);

    expect(InvoiceDocumentResource::canCreate())->toBeFalse()
        ->and(InvoiceDocumentResource::canEdit($invoice))->toBeFalse()
        ->and(InvoiceDocumentResource::canDelete($invoice))->toBeFalse();
});

it('shows retry only on failed documents and dispatches the job', function () {
    grantMoloniPermission();
    Queue::fake();
    $failed = InvoiceDocument::factory()->failed()->create();
    $issued = issuedInvoice();

    Livewire::test(ListInvoiceDocuments::class)
        ->assertActionHidden(TestAction::make('retry')->table($issued))
        ->callAction(TestAction::make('retry')->table($failed))
        ->assertNotified(__('The document was sent to Moloni again.'));

    expect($failed->fresh()?->status)->toBe(DocumentStatus::Pending);
    Queue::assertPushed(IssueDocument::class, 1);
});

it('shows what the manager refuses as a notification', function () {
    grantMoloniPermission();
    Queue::fake();
    $failed = InvoiceDocument::factory()->failed()->create();
    $lock = IssueDocument::lockFor($failed);
    $lock->get();

    Livewire::test(ListInvoiceDocuments::class)
        ->callAction(TestAction::make('retry')->table($failed))
        ->assertNotified(__('The document :reference is being processed; try again in a moment.', ['reference' => $failed->reference]));

    $lock->release();
    expect($failed->fresh()?->status)->toBe(DocumentStatus::Failed);
    Queue::assertNothingPushed();
});

it('shows each action only where the manager accepts it', function () {
    grantMoloniPermission();
    $invoice = issuedInvoice();
    $unknownInvoice = issuedInvoice(['status' => DocumentStatus::Unknown, 'document_id' => null, 'document_number' => null]);
    $unknownReceipt = InvoiceDocument::factory()->receipt()->unknown()->create();
    $proforma = InvoiceDocument::factory()->proforma()->issued()->create();
    $receipt = InvoiceDocument::factory()->receipt()->issued()->create();
    $pending = InvoiceDocument::factory()->create();

    $visible = [
        'retry' => [],
        'reissue' => [$invoice, $unknownInvoice],
        'reconcile' => [$unknownInvoice, $unknownReceipt],
        'convert' => [$proforma],
        'creditNote' => [$invoice],
        'cancel' => [$invoice],
        'downloadPdf' => [$invoice, $proforma, $receipt],
    ];
    $all = [$invoice, $unknownInvoice, $unknownReceipt, $proforma, $receipt, $pending];
    $component = Livewire::test(ListInvoiceDocuments::class);

    foreach ($visible as $name => $documents) {
        foreach ($all as $document) {
            in_array($document, $documents, true)
                ? $component->assertActionVisible(TestAction::make($name)->table($document))
                : $component->assertActionHidden(TestAction::make($name)->table($document));
        }
    }
});

it('requires a reason to reissue an issued document', function () {
    grantMoloniPermission();
    Moloni::fake();
    $document = Moloni::invoice(Order::create(['customer_vat' => '509999999']));

    Livewire::test(ViewInvoiceDocument::class, ['record' => $document->getKey()])
        ->callAction('reissue', ['reason' => ''])
        ->assertHasActionErrors(['reason' => 'required']);

    expect($document->fresh()?->status)->toBe(DocumentStatus::Issued);

    Livewire::test(ViewInvoiceDocument::class, ['record' => $document->getKey()])
        ->callAction('reissue', ['reason' => 'Wrong billing address'])
        ->assertHasNoActionErrors()
        ->assertNotified(__('A new document was requested in place of this one.'));

    $old = $document->fresh();
    expect($old?->status)->toBe(DocumentStatus::Superseded)
        ->and($old?->payload['reissue']['reason'] ?? null)->toBe('Wrong billing address')
        ->and(InvoiceDocument::query()->where('status', DocumentStatus::Issued)->whereKeyNot($document->getKey())->count())->toBe(1);
});

it('reissues an unconfirmed document without a reason', function () {
    grantMoloniPermission();
    $fake = Moloni::fake();
    $document = $fake->failNext(FailureType::Unknown)->invoice(Order::create(['customer_vat' => '509999999']));

    Livewire::test(ViewInvoiceDocument::class, ['record' => $document->getKey()])
        ->assertActionVisible('reconcile')
        ->callAction('reissue')
        ->assertHasNoActionErrors();

    expect($document->fresh()?->status)->toBe(DocumentStatus::Superseded);
});

it('reconciles an unconfirmed document', function () {
    grantMoloniPermission();
    Queue::fake();
    $document = InvoiceDocument::factory()->receipt()->unknown()->create();

    Livewire::test(ViewInvoiceDocument::class, ['record' => $document->getKey()])
        ->callAction('reconcile')
        ->assertNotified(__('Moloni is being checked for this document again.'));

    Queue::assertPushed(IssueDocument::class, 1);
});

it('converts an issued pro-forma into an invoice-receipt paid in full', function () {
    grantMoloniPermission();
    $fake = Moloni::fake();
    $proforma = $fake->proforma(Order::create(['customer_vat' => '509999999']), 'quote-1');
    // The form offers the payment methods configured in the settings.
    MoloniSettings::fake(['payment_methods' => ['transfer' => 22]]);

    Livewire::test(ViewInvoiceDocument::class, ['record' => $proforma->getKey()])
        ->callAction('convert', ['type' => DocumentType::InvoiceReceipt->value, 'payment_method' => 'transfer'])
        ->assertHasNoActionErrors()
        ->assertNotified(__('The pro-forma is being converted.'));

    $fake->assertIssued(DocumentType::InvoiceReceipt, fn (InvoiceDocument $document) => $document->reference === $proforma->reference
        && data_get($document->payload, 'payment.method') === 'transfer'
        && data_get($document->payload, 'payment.amountCents') === $proforma->expected_total_cents);
});

it('records one credit note when the same form is submitted twice', function () {
    grantMoloniPermission();
    $fake = Moloni::fake();
    $document = $fake->invoice(Order::create(['customer_vat' => '509999999']));

    foreach ([1, 2] as $attempt) {
        Livewire::test(ViewInvoiceDocument::class, ['record' => $document->getKey()])
            ->callAction('creditNote', ['idempotency_key' => 'form-1'])
            ->assertHasNoActionErrors();
    }

    expect(InvoiceDocument::query()->where('type', DocumentType::CreditNote)->count())->toBe(1);

    $component = Livewire::test(ViewInvoiceDocument::class, ['record' => $document->getKey()])->mountAction('creditNote');
    expect($component->instance()->mountedActions[0]['data']['idempotency_key'] ?? null)->toMatch('/^[0-9a-f-]{36}$/');
});

it('offers a full credit note when Moloni refuses the cancellation', function () {
    grantMoloniPermission();
    Queue::fake();
    useIssueSettings(['document_sets' => ['invoice' => 41, 'invoice_receipt' => 42, 'credit_note' => 44]]);
    Http::fake([
        '*/grant/*' => moloniGrant(),
        '*/documents/documentCancel/*' => ['valid' => 0, 'message' => [['code' => '10 associated_id', 'description' => 'The associated document is not valid.']]],
        '*/documents/getOne/*' => moloniDocumentRow(['document_id' => 8001, 'status' => 1]),
    ]);
    $document = issuedInvoice();

    $component = Livewire::test(ListInvoiceDocuments::class)
        ->callAction(TestAction::make('cancel')->table($document), ['reason' => 'Order cancelled'])
        ->assertNotified(__('Moloni refused to cancel :document: :reason', ['document' => 'GR/8', 'reason' => 'The associated document is not valid.']))
        ->assertActionMounted(TestAction::make('creditNote')->table($document));
    $key = $component->instance()->mountedActions[0]['data']['idempotency_key'] ?? null;
    expect($key)->toBeString()->toMatch('/^[0-9a-f-]{36}$/');

    $component->callMountedAction()
        ->assertNotified(__('A credit note was requested for this document.'));

    // The credit note was requested under the key generated when its form opened.
    $credit = InvoiceDocument::query()->where('type', DocumentType::CreditNote)->sole();
    expect(Moloni::creditNote($document, null, (string) $key)->is($credit))->toBeTrue()
        ->and(InvoiceDocument::query()->where('type', DocumentType::CreditNote)->count())->toBe(1)
        ->and($document->fresh()?->status)->toBe(DocumentStatus::Issued)
        ->and($credit->status)->toBe(DocumentStatus::Pending);
    Queue::assertPushed(IssueDocument::class, 1);
});

it('cancels an issued document outside any database transaction', function () {
    grantMoloniPermission();
    useIssueSettings();
    Http::fake([
        '*/grant/*' => moloniGrant(),
        '*/documents/documentCancel/*' => ['valid' => 1],
    ]);
    $document = issuedInvoice();
    // A host panel with ->databaseTransactions() wraps every action in one; cancel() refuses that.
    $restore = Action::configureUsing(fn (Action $action) => $action->databaseTransaction());

    try {
        expect(CancelDocumentAction::make()->hasDatabaseTransactions())->toBeFalse();

        Livewire::test(ListInvoiceDocuments::class)
            ->callAction(TestAction::make('cancel')->table($document), ['reason' => 'Order cancelled'])
            ->assertHasNoActionErrors()
            ->assertNotified(__('The document was cancelled in Moloni.'));
    } finally {
        $restore();
    }

    expect($document->fresh()?->status)->toBe(DocumentStatus::Voided);
});

it('redirects the download action to a link fetched at click time', function () {
    grantMoloniPermission();
    useIssueSettings();
    Http::preventStrayRequests();
    Http::fake([
        '*/grant/*' => moloniGrant(),
        '*/documents/getPDFLink/*' => ['url' => 'https://moloni.example.test/pdf/abc'],
    ]);
    $document = issuedInvoice();

    $component = Livewire::test(ListInvoiceDocuments::class)
        ->assertDontSee('moloni.example.test');
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'getPDFLink'));

    $component->callAction(TestAction::make('downloadPdf')->table($document))
        ->assertRedirect('https://moloni.example.test/pdf/abc');

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'getPDFLink') && (int) $request['document_id'] === 8001);
    expect(json_encode($document->fresh()?->toArray()))->not->toContain('moloni.example.test');
});

it('keeps every action usable outside the resource', function () {
    foreach ([RetryDocumentAction::class, ReissueDocumentAction::class, ReconcileDocumentAction::class, ConvertProformaAction::class, CreditNoteAction::class, CancelDocumentAction::class, DownloadPdfAction::class] as $action) {
        expect($action::make())->toBeInstanceOf(Action::class)
            ->and($action::make()->hasDatabaseTransactions())->toBeFalse();
    }
});
