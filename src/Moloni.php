<?php

namespace Noop\Moloni;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;
use Noop\Moloni\Actions\RequestReceipt;
use Noop\Moloni\Contracts\Invoiceable;
use Noop\Moloni\Data\InvoiceData;
use Noop\Moloni\Data\PaymentData;
use Noop\Moloni\Enums\DocumentStatus;
use Noop\Moloni\Enums\DocumentType;
use Noop\Moloni\Enums\FailureType;
use Noop\Moloni\Exceptions\MoloniException;
use Noop\Moloni\Jobs\IssueDocument;
use Noop\Moloni\Models\InvoiceDocument;
use Noop\Moloni\Models\InvoiceDocumentLink;
use Noop\Moloni\Settings\MoloniSettings;
use Noop\Moloni\Support\Message;
use Noop\Moloni\Support\Payload;
use Noop\Moloni\Support\Vat;

/**
 * Requests Moloni documents. A request only records a pending row (reading the settings, never
 * Moloni) and queues IssueDocument once the caller's transaction commits.
 */
class Moloni
{
    /** The only currency the package issues in for now. */
    private const CURRENCY = 'EUR';

    /** The width of invoice_documents.operation_key. */
    private const MAX_OPERATION_KEY_LENGTH = 255;

    public function __construct(
        private readonly MoloniClient $client,
        private readonly RequestReceipt $receipts,
    ) {}

    /**
     * An invoice due in $dueInDays days, which needs a maturity date configured for that term.
     * Returns the invoiceable's live invoice or invoice-receipt instead when there is one.
     *
     * On a `sync` queue connection inside the caller's transaction, the job runs after the commit
     * and its exception surfaces then; the row stays Pending and moloni:recover picks it up.
     */
    public function invoice(Invoiceable $invoiceable, int $dueInDays = 0): InvoiceDocument
    {
        return $this->request(DocumentType::Invoice, $invoiceable, null, $dueInDays);
    }

    /**
     * An invoice-receipt paid in full by $payment. Returns the invoiceable's live invoice or
     * invoice-receipt instead when there is one.
     *
     * On a `sync` queue connection inside the caller's transaction, the job runs after the commit
     * and its exception surfaces then; the row stays Pending and moloni:recover picks it up.
     */
    public function invoiceReceipt(Invoiceable $invoiceable, PaymentData $payment): InvoiceDocument
    {
        return $this->request(DocumentType::InvoiceReceipt, $invoiceable, $payment, 0);
    }

    /**
     * A pro-forma, valid for `moloni.proforma_valid_days` days: not a fiscal invoice, and an
     * invoiceable may have several. The same $idempotencyKey returns the same pro-forma, whatever
     * its status; a failed one is sent again, as retry() does. A new pro-forma needs a new key.
     *
     * @throws InvalidArgumentException When the key is empty or too long.
     * @throws MoloniException When the settings cannot issue a pro-forma (Validation).
     */
    public function proforma(Invoiceable $invoiceable, string $idempotencyKey): InvoiceDocument
    {
        if (trim($idempotencyKey) === '') {
            throw new InvalidArgumentException(Message::get('A pro-forma idempotency key cannot be empty.'));
        }

        $model = $this->model($invoiceable);
        $operationKey = InvoiceDocument::operationKey(DocumentType::Proforma, $model, $idempotencyKey);

        if (strlen($operationKey) > self::MAX_OPERATION_KEY_LENGTH) {
            throw new InvalidArgumentException(Message::get('The pro-forma idempotency key is too long.'));
        }

        $existing = InvoiceDocument::query()->where('operation_key', $operationKey)->first();

        if ($existing !== null) {
            if ($existing->status === DocumentStatus::Failed) {
                $this->retry($existing);
            }

            return $existing;
        }

        $validDays = config('moloni.proforma_valid_days', 30);
        $attributes = $this->attributes(DocumentType::Proforma, $invoiceable, null, is_numeric($validDays) ? (int) $validDays : 30);

        try {
            $document = $model->getConnection()->transaction(fn () => $this->create(DocumentType::Proforma, $invoiceable, $attributes, $operationKey));
        } catch (UniqueConstraintViolationException $e) {
            // A concurrent request with the same key created it first.
            return InvoiceDocument::query()->where('operation_key', $operationKey)->first() ?? throw $e;
        }

        $this->dispatch($document);

        return $document;
    }

    /**
     * Converts an issued pro-forma into an invoice due in $dueInDays days or, with $payment, an
     * invoice-receipt, built from what the pro-forma quoted (its frozen request, not the
     * invoiceable's current data) and associated with it in Moloni, which refuses the association
     * once the pro-forma is cancelled or fully converted: the document then fails.
     *
     * Converting again while the conversion is live returns it. A pro-forma whose conversion
     * failed or was voided can be converted again; one whose invoiceable has another live invoice
     * or invoice-receipt cannot.
     *
     * @throws MoloniException When the pro-forma cannot be converted or the settings cannot issue the document (Validation).
     */
    public function convert(InvoiceDocument $proforma, ?PaymentData $payment = null, int $dueInDays = 0): InvoiceDocument
    {
        // The job updates the row, so the caller's copy may be stale.
        $proforma->refresh();
        $this->assertConvertible($proforma);
        $invoiceable = $proforma->invoiceable;

        if (! $invoiceable instanceof Invoiceable) {
            throw new MoloniException(__('The pro-forma :reference has no invoiceable to convert it for.', ['reference' => $proforma->reference]), FailureType::Validation);
        }

        $type = $payment === null ? DocumentType::Invoice : DocumentType::InvoiceReceipt;

        [$document, $created] = $this->whileHolding($proforma, function () use ($proforma, $invoiceable, $type, $payment, $dueInDays) {
            try {
                return $proforma->getConnection()->transaction(function () use ($proforma, $invoiceable, $type, $payment, $dueInDays) {
                    $current = InvoiceDocument::query()->whereKey($proforma->getKey())->lockForUpdate()->firstOrFail();
                    $this->assertConvertible($current);
                    $conversion = $this->conversionOf($current);

                    if ($conversion !== null) {
                        return [$conversion, false];
                    }

                    if ($this->live($this->model($invoiceable)) !== null) {
                        throw new MoloniException(__('Another live document already exists for :reference.', ['reference' => $current->reference]), FailureType::Validation);
                    }

                    $quoted = InvoiceData::fromArray(Payload::requiredArray($current->payload ?? [], 'invoice', 'pro-forma'));
                    $attributes = $this->attributes($type, $invoiceable, $payment, $dueInDays, $quoted);
                    $attributes['payload']['converts'] = [
                        'proforma_document_id' => $current->id,
                        'moloni_document_id' => (int) $current->document_id,
                    ];
                    $document = $this->create($type, $invoiceable, $attributes);
                    $this->linkConversion($document);

                    return [$document, true];
                });
            } catch (UniqueConstraintViolationException) {
                throw new MoloniException(__('Another live document already exists for :reference.', ['reference' => $proforma->reference]), FailureType::Validation);
            }
        });

        if ($created) {
            $this->dispatch($document);
        }

        return $document;
    }

    /**
     * One receipt for $payment, settling issued invoices of one Moloni customer with an amount per
     * invoice: $allocations is a list of [InvoiceDocument, cents]. The amounts must add up to the
     * payment and fit what each invoice still owes; invoices of a final consumer are settled one
     * per receipt. $owner (any saved model) becomes the receipt's invoiceable.
     *
     * Without $idempotencyKey every call records a new receipt. A caller reacting to a payment
     * should pass the payment's id: a repeated call with the same invoices, amounts and payment
     * method returns the same receipt (and sends a failed one again, as retry() does); other
     * invoices, amounts or method under that key are refused, since the key already belongs to
     * another receipt.
     *
     * At most RequestReceipt::MAX_INVOICES invoices per receipt. The invoices are locked until
     * the caller's transaction ends: requesting receipts on overlapping invoices in opposite
     * orders within one host transaction can deadlock (a QueryException), so request one receipt
     * per transaction.
     *
     * @param  list<array{0: InvoiceDocument, 1: int}>  $allocations
     *
     * @throws InvalidArgumentException When the allocations or the payment cannot be settled.
     * @throws MoloniException When the settings cannot issue a receipt (Validation).
     */
    public function receipt(Model $owner, array $allocations, PaymentData $payment, ?string $idempotencyKey = null): InvoiceDocument
    {
        [$receipt, $created] = ($this->receipts)($owner, $allocations, $payment, $idempotencyKey);

        if ($created) {
            $this->dispatch($receipt);
        } elseif ($receipt->status === DocumentStatus::Failed) {
            $this->retry($receipt);
        }

        return $receipt;
    }

    /**
     * Replaces an issued or unknown invoice or invoice-receipt with a new one, built from the
     * invoiceable's current data, with the same type and, unless given, the same payment and term.
     * The old row is marked superseded; nothing is cancelled in Moloni.
     */
    public function reissue(InvoiceDocument $document, ?PaymentData $payment = null, ?int $dueInDays = null): InvoiceDocument
    {
        // The job updates the row, so the caller's copy may be stale.
        $document->refresh();
        $this->assertReissuable($document);
        $invoiceable = $document->invoiceable;

        if (! $invoiceable instanceof Invoiceable) {
            throw new MoloniException(__('The document :reference has no invoiceable to reissue it from.', ['reference' => $document->reference]), FailureType::Validation);
        }

        $frozen = $document->payload ?? [];
        $frozenPayment = $frozen['payment'] ?? null;
        $payment ??= $document->type === DocumentType::InvoiceReceipt && is_array($frozenPayment) ? PaymentData::fromArray($frozenPayment) : null;
        $dueInDays ??= is_numeric($frozen['due_in_days'] ?? null) ? (int) $frozen['due_in_days'] : 0;

        $attributes = $this->attributes($document->type, $invoiceable, $payment, $dueInDays);

        if (is_array($frozen['converts'] ?? null)) {
            // The new document converts the same pro-forma, with its own link.
            $attributes['payload']['converts'] = $frozen['converts'];
        }

        $new = $this->whileHolding($document, function () use ($document, $invoiceable, $attributes) {
            try {
                return $document->getConnection()->transaction(function () use ($document, $invoiceable, $attributes) {
                    $old = InvoiceDocument::query()->whereKey($document->getKey())->lockForUpdate()->firstOrFail();
                    $this->assertReissuable($old);
                    $old->status = DocumentStatus::Superseded;
                    $old->save();

                    return tap($this->create($document->type, $invoiceable, $attributes), $this->linkConversion(...));
                });
            } catch (UniqueConstraintViolationException) {
                throw new MoloniException(__('Another live document already exists for :reference.', ['reference' => $document->reference]), FailureType::Validation);
            }
        });

        $document->refresh();
        $this->dispatch($new);

        return $new;
    }

    /**
     * Sends a failed document again, on the same row, with its attempts counted from zero. A
     * receipt is sent again only while its allocations still fit what its invoices owe.
     */
    public function retry(InvoiceDocument $document): void
    {
        $this->whileHolding($document, function () use ($document) {
            try {
                $document->getConnection()->transaction(function () use ($document) {
                    // The caller's copy may be stale: the status is checked on the locked row.
                    $current = InvoiceDocument::query()->whereKey($document->getKey())->lockForUpdate()->firstOrFail();

                    if ($current->status !== DocumentStatus::Failed) {
                        throw new MoloniException(__('Only a failed document can be retried.'), FailureType::Validation);
                    }

                    if ($current->type === DocumentType::Receipt) {
                        $this->receipts->assertStillFits($current);
                    }

                    $current->fill(['status' => DocumentStatus::Pending, 'error_type' => null, 'error_message' => null, 'attempts' => 0])->save();
                });
            } catch (UniqueConstraintViolationException) {
                throw new MoloniException(__('Another live document already exists for :reference.', ['reference' => $document->reference]), FailureType::Validation);
            } finally {
                $document->refresh();
            }
        });

        $this->dispatch($document);
    }

    /**
     * Runs $callback holding the document's lock, the one the issuing job holds while it works,
     * and releases it before anything is dispatched.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function whileHolding(InvoiceDocument $document, callable $callback): mixed
    {
        $lock = IssueDocument::lockFor($document);

        if (! $lock->get()) {
            throw new MoloniException(__('The document :reference is being processed; try again in a moment.', ['reference' => $document->reference]), FailureType::Validation);
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }

    /**
     * A fresh link to the document's PDF, asked of Moloni every time; Moloni's links expire, so
     * never store it.
     */
    public function pdfLink(InvoiceDocument $document): string
    {
        if ($document->status !== DocumentStatus::Issued || $document->document_id === null) {
            throw new MoloniException(__('The document :reference has not been issued in Moloni.', ['reference' => $document->reference]), FailureType::Validation);
        }

        return $this->client->pdfLink($document->document_id);
    }

    private function request(DocumentType $type, Invoiceable $invoiceable, ?PaymentData $payment, int $dueInDays): InvoiceDocument
    {
        $model = $this->model($invoiceable);
        $live = $this->live($model);

        if ($live !== null) {
            return $live;
        }

        $attributes = $this->attributes($type, $invoiceable, $payment, $dueInDays);

        try {
            $document = $model->getConnection()->transaction(fn () => $this->create($type, $invoiceable, $attributes));
        } catch (UniqueConstraintViolationException $e) {
            // A concurrent request took the live slot (or the same sequence) first.
            return $this->live($model) ?? throw $e;
        }

        $this->dispatch($document);

        return $document;
    }

    /**
     * Validates the request against the settings and freezes it.
     *
     * @return array<string, mixed>
     */
    private function attributes(DocumentType $type, Invoiceable $invoiceable, ?PaymentData $payment, int $dueInDays, ?InvoiceData $invoice = null): array
    {
        if ($dueInDays < 0) {
            throw new InvalidArgumentException('The term of a document cannot be negative.');
        }

        $settings = app(MoloniSettings::class);
        $companyId = $settings->companyIdOrNull()
            ?? throw new MoloniException(__('No Moloni company is configured.'), FailureType::Validation);
        $documentSetId = $settings->document_sets[$type->value]
            ?? throw new MoloniException(__('No document set is configured for :type.', ['type' => $type->getLabel()]), FailureType::Validation);

        $invoice ??= $invoiceable->toInvoiceData();

        if ($invoice->currency !== self::CURRENCY) {
            throw new MoloniException(__('Cannot issue in :currency: currency not supported yet.', ['currency' => $invoice->currency]), FailureType::Validation);
        }

        if ($type === DocumentType::InvoiceReceipt) {
            if ($payment === null) {
                throw new InvalidArgumentException('An invoice-receipt needs a payment.');
            }

            if (! isset($settings->payment_methods[$payment->method])) {
                throw new MoloniException(__('The payment method :method is not configured.', ['method' => $payment->method]), FailureType::Validation);
            }

            if ($payment->amountCents !== $invoice->totalCents()) {
                throw new MoloniException(__('The payment of :paid does not match the document total of :total.', [
                    'paid' => number_format($payment->amountCents / 100, 2, '.', ''),
                    'total' => number_format($invoice->totalCents() / 100, 2, '.', ''),
                ]), FailureType::Validation);
            }
        }

        if ($type === DocumentType::Invoice && ! isset($settings->maturity_dates[$dueInDays])) {
            throw new MoloniException(__('No maturity date is configured for :days days.', ['days' => $dueInDays]), FailureType::Validation);
        }

        $vat = $invoice->customer->vat;

        return [
            'status' => DocumentStatus::Pending,
            'company_id' => $companyId,
            'document_set_id' => $documentSetId,
            // Exactly what is sent to Moloni as our_reference, and searched for when reconciling.
            'reference' => '#'.$invoiceable->invoiceReference(),
            'currency' => $invoice->currency,
            'expected_total_cents' => $invoice->totalCents(),
            'customer_vat' => Vat::isFinalConsumer($vat) ? null : $vat,
            'due_at' => self::today()->addDays($dueInDays),
            'vies' => $invoice->vies?->toArray(),
            'payload' => [
                'invoice' => $invoice->toArray(),
                'payment' => $payment?->toArray(),
                'due_in_days' => $dueInDays,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function create(DocumentType $type, Invoiceable $invoiceable, array $attributes, ?string $operationKey = null): InvoiceDocument
    {
        $model = $this->model($invoiceable);
        $operationKey ??= InvoiceDocument::operationKey($type, $model, InvoiceDocument::query()
            ->where('invoiceable_type', $model->getMorphClass())
            ->where('invoiceable_id', $model->getKey())
            ->whereIn('type', [DocumentType::Invoice, DocumentType::InvoiceReceipt])
            ->count() + 1);

        $document = new InvoiceDocument([
            ...$attributes,
            'type' => $type,
            'operation_key' => $operationKey,
        ]);
        $document->invoiceable()->associate($model);
        $document->save();

        return $document;
    }

    private function live(Model $invoiceable): ?InvoiceDocument
    {
        return InvoiceDocument::query()->where('active_key', InvoiceDocument::activeKeyFor($invoiceable))->first();
    }

    private function assertReissuable(InvoiceDocument $document): void
    {
        if ($document->type === DocumentType::Proforma) {
            throw new MoloniException(__('A pro-forma cannot be reissued; request a new one with a new key instead.'), FailureType::Validation);
        }

        $reissuable = in_array($document->type, [DocumentType::Invoice, DocumentType::InvoiceReceipt], true)
            && in_array($document->status, [DocumentStatus::Issued, DocumentStatus::Unknown], true);

        if (! $reissuable) {
            throw new MoloniException(__('Only an issued or unconfirmed invoice or invoice-receipt can be reissued.'), FailureType::Validation);
        }
    }

    private function assertConvertible(InvoiceDocument $proforma): void
    {
        if ($proforma->type !== DocumentType::Proforma || $proforma->status !== DocumentStatus::Issued || $proforma->document_id === null) {
            throw new MoloniException(__('Only an issued pro-forma can be converted.'), FailureType::Validation);
        }

        if ($proforma->company_id !== app(MoloniSettings::class)->companyIdOrNull()) {
            throw new MoloniException(__('The pro-forma :reference was issued for another Moloni company.', ['reference' => $proforma->reference]), FailureType::Validation);
        }
    }

    /**
     * The live invoice or invoice-receipt converting the pro-forma, if any.
     */
    private function conversionOf(InvoiceDocument $proforma): ?InvoiceDocument
    {
        return InvoiceDocument::query()
            ->whereIn('type', [DocumentType::Invoice, DocumentType::InvoiceReceipt])
            ->whereIn('status', DocumentStatus::live())
            ->whereHas('links', fn (Builder $links) => $links->where('associated_document_id', $proforma->getKey()))
            ->first();
    }

    /**
     * Links a document converting a pro-forma to it, for its total: it does not settle the
     * pro-forma (only receipts and credit notes settle debt).
     */
    private function linkConversion(InvoiceDocument $document): void
    {
        $converts = $document->payload['converts'] ?? null;

        if (is_array($converts)) {
            InvoiceDocumentLink::create([
                'document_id' => $document->id,
                'associated_document_id' => (int) Payload::required($converts, 'proforma_document_id', 'conversion'),
                'amount_cents' => $document->expected_total_cents,
            ]);
        }
    }

    private function model(Invoiceable $invoiceable): Model
    {
        if (! $invoiceable instanceof Model) {
            throw new InvalidArgumentException('An invoiceable must be an Eloquent model.');
        }

        return $invoiceable;
    }

    private function dispatch(InvoiceDocument $document): void
    {
        IssueDocument::dispatch($document);
    }

    /**
     * Today in the application's timezone. Documents are always dated today: Moloni accepts a
     * future date and then refuses earlier ones for that type in the series.
     */
    public static function today(): CarbonImmutable
    {
        $timezone = config('app.timezone');

        return CarbonImmutable::now(is_string($timezone) ? $timezone : null)->startOfDay();
    }
}
