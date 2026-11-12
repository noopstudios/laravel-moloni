<?php

namespace Noop\Moloni;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;
use Noop\Moloni\Contracts\Invoiceable;
use Noop\Moloni\Data\PaymentData;
use Noop\Moloni\Enums\DocumentStatus;
use Noop\Moloni\Enums\DocumentType;
use Noop\Moloni\Enums\FailureType;
use Noop\Moloni\Exceptions\MoloniException;
use Noop\Moloni\Jobs\IssueDocument;
use Noop\Moloni\Models\InvoiceDocument;
use Noop\Moloni\Settings\MoloniSettings;
use Noop\Moloni\Support\Vat;

/**
 * Requests Moloni documents. A request only records a pending row (reading the settings, never
 * Moloni) and queues IssueDocument once the caller's transaction commits.
 */
class Moloni
{
    /** The only currency the package issues in for now. */
    private const CURRENCY = 'EUR';

    public function __construct(private readonly MoloniClient $client) {}

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

        $new = $this->whileHolding($document, function () use ($document, $invoiceable, $attributes) {
            try {
                return $document->getConnection()->transaction(function () use ($document, $invoiceable, $attributes) {
                    $old = InvoiceDocument::query()->whereKey($document->getKey())->lockForUpdate()->firstOrFail();
                    $this->assertReissuable($old);
                    $old->status = DocumentStatus::Superseded;
                    $old->save();

                    return $this->create($document->type, $invoiceable, $attributes);
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
     * Sends a failed document again, on the same row, with its attempts counted from zero.
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
    private function attributes(DocumentType $type, Invoiceable $invoiceable, ?PaymentData $payment, int $dueInDays): array
    {
        if ($dueInDays < 0) {
            throw new InvalidArgumentException('The term of a document cannot be negative.');
        }

        $settings = app(MoloniSettings::class);
        $companyId = $settings->companyIdOrNull()
            ?? throw new MoloniException(__('No Moloni company is configured.'), FailureType::Validation);
        $documentSetId = $settings->document_sets[$type->value]
            ?? throw new MoloniException(__('No document set is configured for :type.', ['type' => $type->getLabel()]), FailureType::Validation);

        $invoice = $invoiceable->toInvoiceData();

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
    private function create(DocumentType $type, Invoiceable $invoiceable, array $attributes): InvoiceDocument
    {
        $model = $this->model($invoiceable);
        $previous = InvoiceDocument::query()
            ->where('invoiceable_type', $model->getMorphClass())
            ->where('invoiceable_id', $model->getKey())
            ->whereIn('type', [DocumentType::Invoice, DocumentType::InvoiceReceipt])
            ->count();

        $document = new InvoiceDocument([
            ...$attributes,
            'type' => $type,
            'operation_key' => InvoiceDocument::operationKey($type, $model, $previous + 1),
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
        $reissuable = in_array($document->type, [DocumentType::Invoice, DocumentType::InvoiceReceipt], true)
            && in_array($document->status, [DocumentStatus::Issued, DocumentStatus::Unknown], true);

        if (! $reissuable) {
            throw new MoloniException(__('Only an issued or unconfirmed invoice or invoice-receipt can be reissued.'), FailureType::Validation);
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
