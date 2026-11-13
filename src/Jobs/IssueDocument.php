<?php

namespace Noop\Moloni\Jobs;

use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Cache\Lock as CacheLock;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Noop\Moloni\Actions\ReconcileDocument;
use Noop\Moloni\Actions\RequestCreditNote;
use Noop\Moloni\Actions\RequestReceipt;
use Noop\Moloni\Data\InvoiceData;
use Noop\Moloni\Data\InvoiceLine;
use Noop\Moloni\Data\PaymentData;
use Noop\Moloni\Enums\DocumentStatus;
use Noop\Moloni\Enums\DocumentType;
use Noop\Moloni\Enums\FailureType;
use Noop\Moloni\Events\DocumentFailed;
use Noop\Moloni\Events\DocumentIssued;
use Noop\Moloni\Exceptions\MoloniException;
use Noop\Moloni\Models\InvoiceDocument;
use Noop\Moloni\Moloni;
use Noop\Moloni\MoloniClient;
use Noop\Moloni\Resolvers\CustomerResolver;
use Noop\Moloni\Resolvers\ProductResolver;
use Noop\Moloni\Resolvers\TaxResolver;
use Noop\Moloni\Settings\MoloniSettings;
use Noop\Moloni\Support\Message;
use Noop\Moloni\Support\Payload;
use Throwable;

/**
 * Issues one document in Moloni from its frozen payload. A document must never be issued twice:
 *
 * - every run that is about to insert first looks for the document in Moloni (ReconcileDocument);
 * - the row moves to Issuing by compare-and-set and is committed before the insert is sent, and
 *   its Moloni id is saved right after;
 * - an insert whose outcome is uncertain leaves the row Unknown and is never retried here.
 *
 * Failures before the insert (customer, products, taxes) are retried with backoff when Moloni may
 * answer differently later; the row's `attempts` (runs that tried to issue it) decides the last
 * attempt, so lock contention and re-dispatches never shorten the retries.
 *
 * Host requirements:
 * - the queue connection's `retry_after` must exceed the job timeout (120 s), or a running job is
 *   handed to a second worker;
 * - the cache store must support atomic locks and be shared by every worker (not `array`, not a
 *   per-server `file` store): the per-document lock is what keeps two runs apart;
 * - the worker must enforce the job timeout (the pcntl extension loaded, `--timeout` honoured), or a
 *   run can outlive its lock; as a last guard a run never sends the insert once it no longer owns
 *   the lock or has run for more than MAX_RUN_SECONDS (the row stays Issuing and is reconciled);
 * - DocumentIssued and DocumentFailed are dispatched at most once per outcome; a crash right after
 *   the state is saved loses the event, never the state.
 */
class IssueDocument implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const TOTAL_MISMATCH = 'total_mismatch';

    public const READBACK_FAILED = 'readback_failed';

    public const COMPANY_CHANGED = 'company_changed';

    public const JOB_FAILED = 'job_failed';

    /** Issued, but still not read back READBACK_HOURS after it was issued: no longer repaired automatically. */
    public const READBACK_ABANDONED = 'readback_abandoned';

    /** How long after issued_at a failed read-back keeps being repaired. */
    public const READBACK_HOURS = 24;

    /** Past this, a run never sends the insert: the lock (LOCK_SECONDS) must outlive the insert call. */
    public const MAX_RUN_SECONDS = 110;

    /** Longer than the timeout: a run never outlives its lock. */
    public const LOCK_SECONDS = 150;

    private const MAX_ERROR_LENGTH = 1000;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public int $timeout = 120;

    /** Bounds the unique lock, so a killed worker cannot keep moloni:recover from dispatching again. */
    public int $uniqueFor = 600;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public InvoiceDocument $document)
    {
        $connection = config('moloni.queue_connection');
        $this->onConnection(is_string($connection) ? $connection : null);
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return (string) $this->document->getKey();
    }

    /**
     * The lock every writer of a document's status takes: this job, Moloni::retry(),
     * Moloni::reissue(), Moloni::cancel() and Moloni::convert() (on the pro-forma).
     */
    public static function lockFor(InvoiceDocument $document): Lock
    {
        return Cache::lock('moloni-doc-'.$document->getKey(), self::LOCK_SECONDS);
    }

    /**
     * The lock Moloni::cancel() holds, besides the document's, while it cancels the document.
     */
    public static function cancelLockFor(InvoiceDocument $document): Lock
    {
        return Cache::lock('moloni-cancel-'.$document->getKey(), self::LOCK_SECONDS);
    }

    /**
     * Refuses (Validation) to create a document acting on $document (a receipt, credit note or
     * conversion) while it is being cancelled. The caller must already hold the row lock, so a
     * cancellation that starts later sees what this caller commits. Only the cancellation's own
     * lock counts: the document's lock is held by its issuing job too, also while DocumentIssued
     * listeners run.
     */
    public static function assertNotBeingCancelled(InvoiceDocument $document): void
    {
        $lock = self::cancelLockFor($document);

        if (! $lock->get()) {
            throw new MoloniException(Message::get('The document :reference is being processed; try again in a moment.', [
                'reference' => $document->document_number ?? $document->reference,
            ]), FailureType::Validation);
        }

        $lock->release();
    }

    public function handle(
        MoloniClient $client,
        ReconcileDocument $reconcile,
        CustomerResolver $customers,
        TaxResolver $taxes,
        ProductResolver $products,
        MoloniSettings $settings,
    ): void {
        $startedAt = now()->toImmutable();
        $lock = self::lockFor($this->document);

        // Another run holds the document: it is working on it, and moloni:recover is the safety
        // net. Releasing would only spend one of this job's tries.
        if (! $lock->get()) {
            return;
        }

        try {
            $document = $this->document->fresh();

            if ($document !== null) {
                $this->issue($document, $client, $reconcile, $customers, $taxes, $products, $settings, $lock, $startedAt);
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * Called by the queue when the job gives up (an exception on its last try, a timeout). It
     * skips a document another run holds. A Pending row with retries left stays for
     * moloni:recover; an Issuing one may exist in Moloni, so it becomes Unknown, never Failed,
     * unless its Moloni id is already saved: it exists, and moloni:recover finishes it by read-back.
     */
    public function failed(?Throwable $e): void
    {
        $lock = self::lockFor($this->document);

        if (! $lock->get()) {
            return;
        }

        try {
            $document = $this->document->fresh();

            if ($document === null) {
                return;
            }

            $message = $e instanceof MoloniException ? $this->message($e) : $this->jobFailedMessage($e);
            $errorType = $e instanceof MoloniException ? $e->type->value : self::JOB_FAILED;

            if ($document->status === DocumentStatus::Issuing && $document->document_id === null) {
                $this->settle($document, DocumentStatus::Unknown, $errorType, $message);
            } elseif ($document->status === DocumentStatus::Pending && $document->attempts >= $this->tries) {
                $this->settle($document, DocumentStatus::Failed, $errorType, $message);
            }
        } finally {
            $lock->release();
        }
    }

    private function issue(
        InvoiceDocument $document,
        MoloniClient $client,
        ReconcileDocument $reconcile,
        CustomerResolver $customers,
        TaxResolver $taxes,
        ProductResolver $products,
        MoloniSettings $settings,
        Lock $lock,
        CarbonImmutable $startedAt,
    ): void {
        if ($this->needsReadBack($document)) {
            // Issued, but its number and total were never read: only read, never insert.
            $this->repairReadBack($document, $client, $settings);

            return;
        }

        if (! in_array($document->status, [DocumentStatus::Pending, DocumentStatus::Issuing, DocumentStatus::Unknown], true)) {
            return;
        }

        if ($settings->companyIdOrNull() !== $document->company_id) {
            // Every read (reconciliation, read-back, resolvers) goes to the configured company, so a
            // document requested for another one could be looked for in the wrong place and sent twice.
            $this->settle(
                $document,
                $document->status === DocumentStatus::Pending ? DocumentStatus::Failed : DocumentStatus::Unknown,
                self::COMPANY_CHANGED,
                Message::get('The document was requested for Moloni company :company, which is no longer the configured company.', ['company' => (string) $document->company_id]),
            );

            return;
        }

        if ($document->document_id !== null) {
            // Inserted, but the job stopped before finishing: never insert again.
            $this->finish($document, $client);

            return;
        }

        $document->attempts++;
        $document->save();

        try {
            if ($reconcile($document)) {
                return;
            }
        } catch (MoloniException $e) {
            $this->failBeforeInsert($document, $e, absentInMoloni: false);

            return;
        }

        $today = Moloni::today();

        try {
            $payload = match ($document->type) {
                DocumentType::Receipt => $this->receiptPayload($document, $settings, $today),
                DocumentType::CreditNote => $this->creditNotePayload($document, $client, $today),
                default => $this->payload($document, $customers, $taxes, $products, $settings, $today),
            };
        } catch (MoloniException $e) {
            // The reconciliation just found nothing, so nothing of this document exists in Moloni.
            $this->failBeforeInsert($document, $e, absentInMoloni: true);

            return;
        }

        $document = $this->markIssuing($document);

        if ($document === null) {
            return;
        }

        if (! $this->stillHolds($lock, $startedAt)) {
            // The lock may already belong to another run. The row stays Issuing with no id, so the
            // next run reconciles before anything is sent.
            return;
        }

        // Committed on its own before the request leaves: from here on the document may exist in
        // Moloni, and a receipt is only looked for there once this is set.
        $document->insert_sent_at = now();
        $document->save();

        try {
            $documentId = $client->insert($document->type, $payload);
        } catch (MoloniException $e) {
            match ($e->type) {
                // Only the token request can fail this way, before anything was sent.
                FailureType::Transient => $this->failBeforeInsert($this->backToPending($document), $e, absentInMoloni: true),
                FailureType::Unknown => $this->settle($document, DocumentStatus::Unknown, $e->type->value, $this->message($e)),
                default => $this->settle($document, DocumentStatus::Failed, $e->type->value, $this->message($e)),
            };

            return;
        } catch (Throwable $e) {
            $this->settle($document, DocumentStatus::Unknown, FailureType::Unknown->value, Message::get('The document may have been issued: :error.', ['error' => class_basename($e)]));

            return;
        }

        $document->document_id = $documentId;
        $document->save();

        $this->finish($document, $client);
    }

    /**
     * Whether this run still owns the document's lock with time to spare for the insert. A lock
     * that cannot say who owns it counts as lost.
     */
    private function stillHolds(Lock $lock, CarbonImmutable $startedAt): bool
    {
        return $lock instanceof CacheLock
            && $lock->isOwnedByCurrentProcess()
            && $startedAt->diffInSeconds(now()) <= self::MAX_RUN_SECONDS;
    }

    /**
     * Reads back an issued document whose read-back failed, whatever the earlier failure was,
     * until READBACK_HOURS after it was issued; while another company is configured it is only
     * skipped. After that the repair is abandoned: the row stays Issued with its Moloni id, and
     * moloni:recover no longer dispatches it.
     */
    private function repairReadBack(InvoiceDocument $document, MoloniClient $client, MoloniSettings $settings): void
    {
        $issuedAt = $document->issued_at ?? $document->created_at ?? now();

        if (now()->greaterThanOrEqualTo($issuedAt->copy()->addHours(self::READBACK_HOURS))) {
            $document->error_type = self::READBACK_ABANDONED;
            $document->save();

            return;
        }

        if ($settings->companyIdOrNull() === $document->company_id) {
            $this->finish($document, $client, announce: false);
        }
    }

    private function needsReadBack(InvoiceDocument $document): bool
    {
        return $document->status === DocumentStatus::Issued
            && $document->error_type === self::READBACK_FAILED
            && $document->document_id !== null;
    }

    /**
     * Compare-and-set to Issuing, committed before the insert: the row is re-read under a row lock
     * and the insert is abandoned when anything moved it since this run loaded it. Returns the
     * row now marked Issuing, or null.
     */
    private function markIssuing(InvoiceDocument $loaded): ?InvoiceDocument
    {
        return $loaded->getConnection()->transaction(function () use ($loaded) {
            $current = InvoiceDocument::query()->whereKey($loaded->getKey())->lockForUpdate()->first();

            if ($current === null
                || $current->status !== $loaded->status
                || $current->document_id !== null
                || $current->attempts !== $loaded->attempts) {
                return null;
            }

            $current->fill([
                'status' => DocumentStatus::Issuing,
                'customer_id' => $loaded->customer_id,
                'customer_number' => $loaded->customer_number,
                'due_at' => $loaded->due_at,
            ])->save();

            return $current;
        });
    }

    /**
     * Resolves the customer, products and taxes, and builds the insert payload from the frozen
     * request. Sets the customer and due date on the row (not saved: issuing() takes them over).
     *
     * @return array<string, mixed>
     */
    private function payload(
        InvoiceDocument $document,
        CustomerResolver $customers,
        TaxResolver $taxes,
        ProductResolver $products,
        MoloniSettings $settings,
        CarbonImmutable $today,
    ): array {
        $frozen = $document->payload ?? throw new InvalidArgumentException("The document {$document->getKey()} has no frozen payload.");
        $invoice = InvoiceData::fromArray(Payload::requiredArray($frozen, 'invoice', 'document'));
        $payment = is_array($frozen['payment'] ?? null) ? PaymentData::fromArray($frozen['payment']) : null;
        $dueInDays = is_numeric($frozen['due_in_days'] ?? null) ? (int) $frozen['due_in_days'] : 0;

        $customer = $customers->resolve($invoice->customer);
        $document->customer_id = $customer->id;
        $document->customer_number = $customer->number;
        $document->due_at = Carbon::instance($today->addDays($dueInDays));

        $lines = [];
        $sentLines = [];

        foreach ($invoice->lines as $line) {
            $taxes->assertExemption($line);
            $productId = $products->resolve($line);

            if ($line->isZeroRated()) {
                $rate = 0.0;
                $lineTaxes = ['taxes' => [], 'exemption_reason' => $line->exemptionReason];
            } else {
                // Moloni never inherits the product's taxes, so every taxed line carries its tax.
                $rate = $taxes->rateFor($line->taxRate);
                $lineTaxes = ['taxes' => [['tax_id' => $taxes->taxIdFor($line->taxRate), 'value' => $rate, 'order' => 1, 'cumulative' => 0]]];
            }

            $lines[] = [
                'product_id' => $productId,
                'name' => $line->name,
                'summary' => $line->summary,
                'qty' => $line->quantity,
                'price' => $line->unitNetPrice,
                ...$lineTaxes,
            ];
            $sentLines[] = new InvoiceLine($line->name, $line->quantity, $line->unitNetPrice, $rate, $line->exemptionReason);
        }

        if ((new InvoiceData($invoice->customer, $sentLines))->totalCents() !== $document->expected_total_cents) {
            throw new MoloniException(__("The document total changes with Moloni's tax rates: line tax rates must match Moloni's rates exactly."), FailureType::Validation);
        }

        // A pro-forma's term is its validity, not a payment term.
        $maturityDateId = $document->type === DocumentType::Proforma ? null : ($settings->maturity_dates[$dueInDays] ?? null);
        $converts = is_array($frozen['converts'] ?? null) ? $frozen['converts'] : null;
        // A reissue whose predecessor reached Moloni converts without a new association.
        $associate = $converts !== null && ($converts['associate'] ?? true) !== false;

        if ($maturityDateId === null && $document->type === DocumentType::Invoice) {
            throw new MoloniException(__('No maturity date is configured for :days days.', ['days' => $dueInDays]), FailureType::Validation);
        }

        return [
            'company_id' => $document->company_id,
            'date' => $today->toDateString(),
            'expiration_date' => $today->addDays($dueInDays)->toDateString(),
            'document_set_id' => $document->document_set_id,
            'customer_id' => $customer->id,
            'our_reference' => $document->reference,
            ...($invoice->yourReference !== null ? ['your_reference' => $invoice->yourReference] : []),
            ...($maturityDateId !== null ? ['maturity_date_id' => $maturityDateId] : []),
            'status' => 1,
            ...($invoice->notes !== null ? ['notes' => $invoice->notes] : []),
            'products' => $lines,
            // A conversion: Moloni refuses it once the pro-forma is cancelled or fully converted,
            // and the document then fails; it is never sent without the association.
            ...($associate ? ['associated_documents' => [[
                'associated_id' => (int) Payload::required($converts, 'moloni_document_id', 'conversion'),
                'value' => round($document->expected_total_cents / 100, 2),
            ]]] : []),
            ...($document->type === DocumentType::InvoiceReceipt ? ['payments' => [$this->payment($document, $payment, $settings)]] : []),
        ];
    }

    /**
     * A receipt needs no customer, product or tax resolution: its customer and allocations were
     * fixed when it was requested. Moloni checks each allocation against the invoice's open
     * balance but not the total against the allocations, which the request already guarantees.
     *
     * @return array<string, mixed>
     */
    private function receiptPayload(InvoiceDocument $document, MoloniSettings $settings, CarbonImmutable $today): array
    {
        $frozen = $document->payload ?? throw new InvalidArgumentException("The receipt {$document->getKey()} has no frozen payload.");
        $payment = PaymentData::fromArray(Payload::requiredArray($frozen, 'payment', 'receipt'));
        $customerId = $document->customer_id ?? throw new InvalidArgumentException("The receipt {$document->getKey()} has no Moloni customer.");

        return [
            'company_id' => $document->company_id,
            'date' => $today->toDateString(),
            'document_set_id' => $document->document_set_id,
            'customer_id' => $customerId,
            'net_value' => round($document->expected_total_cents / 100, 2),
            'associated_documents' => array_map(fn (array $allocation) => [
                'associated_id' => $allocation['moloni_document_id'],
                'value' => round($allocation['amount_cents'] / 100, 2),
            ], RequestReceipt::allocationsOf($document)),
            'payments' => [$this->payment($document, $payment, $settings)],
            'status' => 1,
        ];
    }

    /**
     * A credit note needs no customer, product or tax resolution: it credits lines of a document
     * Moloni already has. The origin is read from Moloni, each credited line is matched by its
     * position to the line Moloni stored (which must still be the one requested: same count,
     * name, quantity and price, and product when the request named it), and is sent with
     * Moloni's line id (related_id), stored price and taxes. The association claims what the
     * request fixed (at most the open balance, which Moloni refuses to exceed).
     *
     * @return array<string, mixed>
     */
    private function creditNotePayload(InvoiceDocument $document, MoloniClient $client, CarbonImmutable $today): array
    {
        // No expiration_date or maturity_date_id: Moloni accepts a credit note without them (sandbox).
        $credit = RequestCreditNote::creditOf($document);
        $frozen = InvoiceData::fromArray(Payload::requiredArray($document->payload ?? [], 'invoice', 'credit note'));
        $originInvoice = InvoiceDocument::query()->find($credit['origin_document_id'])?->payload['invoice'] ?? null;
        $originLines = is_array($originInvoice) ? InvoiceData::fromArray($originInvoice)->lines : [];
        $row = $client->document($credit['moloni_document_id']);
        $stored = $row['products'] ?? null;
        $customerId = $row['customer_id'] ?? null;

        if (! is_array($stored) || ! array_is_list($stored) || count($stored) !== count($originLines)
            || ! is_numeric($customerId) || ($document->customer_id !== null && (int) $customerId !== $document->customer_id)) {
            throw $this->originMismatch($document);
        }

        $lines = [];

        foreach ($credit['lines'] as $position => $line) {
            $requested = $originLines[$line['index']] ?? null;
            $moloni = $stored[$line['index']] ?? null;

            if ($requested === null || ! is_array($moloni) || ! $this->sameLine($requested, $moloni)) {
                throw $this->originMismatch($document);
            }

            $lines[] = [
                'product_id' => (int) $moloni['product_id'],
                'name' => $requested->name,
                'qty' => $frozen->lines[$position]->quantity,
                'price' => (float) $moloni['price'],
                'related_id' => (int) $moloni['document_product_id'],
                ...$this->storedTaxes($document, $moloni),
            ];
        }

        $document->customer_id = (int) $customerId;

        return [
            'company_id' => $document->company_id,
            'date' => $today->toDateString(),
            'document_set_id' => $document->document_set_id,
            'customer_id' => (int) $customerId,
            'our_reference' => $document->reference,
            'status' => 1,
            'products' => $lines,
            'associated_documents' => [[
                'associated_id' => $credit['moloni_document_id'],
                'value' => round($credit['applied_cents'] / 100, 2),
            ]],
        ];
    }

    /**
     * Whether the line Moloni stored is the line the document requested.
     *
     * @param  array<array-key, mixed>  $moloni  A line of documents/getOne's products.
     */
    private function sameLine(InvoiceLine $requested, array $moloni): bool
    {
        $decimal = fn (mixed $value) => BigDecimal::of(is_float($value) ? var_export($value, true) : (string) $value);

        return is_numeric($moloni['document_product_id'] ?? null)
            && is_numeric($moloni['product_id'] ?? null)
            && is_numeric($moloni['qty'] ?? null)
            && is_numeric($moloni['price'] ?? null)
            && ($moloni['name'] ?? null) === $requested->name
            && ($requested->productId === null || (int) $moloni['product_id'] === $requested->productId)
            && $decimal($moloni['qty'])->isEqualTo($decimal($requested->quantity))
            && $decimal($moloni['price'])->isEqualTo($requested->unitNetPrice);
    }

    /**
     * The taxes Moloni stored on the origin's line, or its exemption when it has none.
     *
     * @param  array<array-key, mixed>  $moloni
     * @return array<string, mixed>
     */
    private function storedTaxes(InvoiceDocument $document, array $moloni): array
    {
        $taxes = $moloni['taxes'] ?? [];

        if (! is_array($taxes)) {
            throw $this->originMismatch($document);
        }

        if ($taxes === []) {
            $reason = $moloni['exemption_reason'] ?? null;

            if (! is_string($reason) || $reason === '') {
                throw $this->originMismatch($document);
            }

            return ['taxes' => [], 'exemption_reason' => $reason];
        }

        $sent = [];

        foreach ($taxes as $tax) {
            if (! is_array($tax) || ! is_numeric($tax['tax_id'] ?? null) || ! is_numeric($tax['value'] ?? null)) {
                throw $this->originMismatch($document);
            }

            $sent[] = [
                'tax_id' => (int) $tax['tax_id'],
                'value' => (float) $tax['value'],
                'order' => is_numeric($tax['order'] ?? null) ? (int) $tax['order'] : count($sent) + 1,
                'cumulative' => is_numeric($tax['cumulative'] ?? null) ? (int) $tax['cumulative'] : 0,
            ];
        }

        return ['taxes' => $sent];
    }

    private function originMismatch(InvoiceDocument $document): MoloniException
    {
        return new MoloniException(Message::get('The document credited by :reference no longer matches its lines in Moloni.', ['reference' => $document->reference]), FailureType::Validation);
    }

    /**
     * Exactly the document total: Moloni does not check the payments against it.
     *
     * @return array<string, mixed>
     */
    private function payment(InvoiceDocument $document, ?PaymentData $payment, MoloniSettings $settings): array
    {
        if ($payment === null) {
            throw new InvalidArgumentException("The {$document->type->value} {$document->getKey()} has no frozen payment.");
        }

        $methodId = $settings->payment_methods[$payment->method]
            ?? throw new MoloniException(__('The payment method :method is not configured.', ['method' => $payment->method]), FailureType::Validation);

        return [
            'payment_method_id' => $methodId,
            'date' => CarbonImmutable::instance($payment->paidAt)->setTimezone(Moloni::TIMEZONE)->toDateString(),
            'value' => round($document->expected_total_cents / 100, 2),
            ...($payment->notes !== null ? ['notes' => $payment->notes] : []),
        ];
    }

    /**
     * Reads the inserted document back for its number and total. The document is issued whatever
     * the read-back says; a total that differs from the expected one is flagged.
     */
    private function finish(InvoiceDocument $document, MoloniClient $client, bool $announce = true): void
    {
        $errorType = null;
        $errorMessage = null;

        try {
            $row = $client->document((int) $document->document_id);
            $document->document_number = MoloniClient::documentNumber($row);
            $total = MoloniClient::cents($row['net_value'] ?? null);

            if ($total !== $document->expected_total_cents) {
                $errorType = self::TOTAL_MISMATCH;
                $errorMessage = Message::get('Moloni computed a total of :moloni instead of :expected.', [
                    'moloni' => $total === null ? '?' : number_format($total / 100, 2, '.', ''),
                    'expected' => number_format($document->expected_total_cents / 100, 2, '.', ''),
                ]);
            }
        } catch (Throwable $e) {
            $errorType = self::READBACK_FAILED;
            $errorMessage = $e instanceof MoloniException
                ? $this->message($e)
                : Message::get('The document could not be read back: :error.', ['error' => class_basename($e)]);
        }

        $document->fill([
            'status' => DocumentStatus::Issued,
            'issued_at' => $document->issued_at ?? now(),
            'error_type' => $errorType,
            'error_message' => $errorMessage === null ? null : Str::limit($errorMessage, self::MAX_ERROR_LENGTH),
        ])->save();

        if ($announce) {
            Event::dispatch(new DocumentIssued($document));
        }
    }

    /**
     * A failure before the insert. What may work later (a lost connection, an uncertain customer
     * or product write, which the resolvers find on the next attempt) is retried with backoff
     * until the row's last attempt. Giving up fails the row when nothing of it can exist in
     * Moloni (it is Pending, or this run's reconciliation found nothing); otherwise it is left
     * Unknown, keeping an ambiguous reconciliation's error when it already had one.
     */
    private function failBeforeInsert(InvoiceDocument $document, MoloniException $e, bool $absentInMoloni): void
    {
        $retryable = in_array($e->type, [FailureType::Transient, FailureType::Unknown], true);

        if ($retryable && $document->attempts < $this->tries) {
            throw $e;
        }

        $absent = $absentInMoloni || $document->status === DocumentStatus::Pending;

        if (! $absent && $document->status === DocumentStatus::Unknown && $document->error_type === ReconcileDocument::AMBIGUOUS) {
            // Still unresolved: what a person must check in Moloni stays on the row.
            return;
        }

        $this->settle($document, $absent ? DocumentStatus::Failed : DocumentStatus::Unknown, $e->type->value, $this->message($e));
    }

    /**
     * Nothing was sent: the insert_sent_at mark goes too, so the next run does not look for this
     * document in Moloni (where it could adopt another one) as if it had been sent.
     */
    private function backToPending(InvoiceDocument $document): InvoiceDocument
    {
        $document->status = DocumentStatus::Pending;
        $document->insert_sent_at = null;
        $document->save();

        return $document;
    }

    private function jobFailedMessage(?Throwable $e): string
    {
        // Other exceptions (database errors, …) may carry data in their message: only the class is kept.
        return Message::get('The issuing job failed: :error.', ['error' => $e !== null ? class_basename($e) : '?']);
    }

    private function settle(InvoiceDocument $document, DocumentStatus $status, string $errorType, string $message): void
    {
        $document->fill([
            'status' => $status,
            'error_type' => $errorType,
            'error_message' => Str::limit($message, self::MAX_ERROR_LENGTH),
        ])->save();

        Event::dispatch(new DocumentFailed($document));
    }

    /**
     * The exception's message and Moloni's error descriptions (already redacted by the connector).
     */
    private function message(MoloniException $e): string
    {
        $details = collect($e->errors)
            ->map(fn (mixed $error) => is_array($error) ? ($error['description'] ?? $error['code'] ?? null) : $error)
            ->filter(fn (mixed $detail) => is_string($detail) && $detail !== '')
            ->unique()
            ->implode('; ');

        return $details === '' ? $e->getMessage() : $e->getMessage().' '.$details;
    }
}
