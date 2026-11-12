<?php

namespace Noop\Moloni\Jobs;

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

    /** Issued, but its read-back failed for good or too often: no longer repaired automatically. */
    public const READBACK_ABANDONED = 'readback_abandoned';

    /** The runs (counted in `attempts`) after which a failing read-back is no longer repaired. */
    public const MAX_READBACK_ATTEMPTS = 10;

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
     * The lock every writer of a document's status takes: this job, Moloni::retry() and
     * Moloni::reissue().
     */
    public static function lockFor(InvoiceDocument $document): Lock
    {
        return Cache::lock('moloni-doc-'.$document->getKey(), self::LOCK_SECONDS);
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
            $payload = $document->type === DocumentType::Receipt
                ? $this->receiptPayload($document, $settings, $today)
                : $this->payload($document, $customers, $taxes, $products, $settings, $today);
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
     * Reads back an issued document whose read-back failed. A permanent failure (the document
     * cannot be read, the company changed) or too many attempts abandon the repair: the row stays
     * Issued with its Moloni id, and moloni:recover no longer dispatches it.
     */
    private function repairReadBack(InvoiceDocument $document, MoloniClient $client, MoloniSettings $settings): void
    {
        if ($settings->companyIdOrNull() !== $document->company_id) {
            $document->fill([
                'error_type' => self::READBACK_ABANDONED,
                'error_message' => Message::get('The document was requested for Moloni company :company, which is no longer the configured company.', ['company' => (string) $document->company_id]),
            ])->save();

            return;
        }

        $document->attempts++;
        $document->save();

        $error = $this->finish($document, $client, announce: false);
        $permanent = $error instanceof MoloniException && $error->type === FailureType::Validation;

        if ($error !== null && ($permanent || $document->attempts >= self::MAX_READBACK_ATTEMPTS)) {
            $document->error_type = self::READBACK_ABANDONED;
            $document->save();
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

        $maturityDateId = $settings->maturity_dates[$dueInDays] ?? null;

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
        $timezone = config('app.timezone');

        return [
            'payment_method_id' => $methodId,
            'date' => CarbonImmutable::instance($payment->paidAt)->setTimezone(is_string($timezone) ? $timezone : 'UTC')->toDateString(),
            'value' => round($document->expected_total_cents / 100, 2),
            ...($payment->notes !== null ? ['notes' => $payment->notes] : []),
        ];
    }

    /**
     * Reads the inserted document back for its number and total. The document is issued whatever
     * the read-back says; a total that differs from the expected one is flagged. Returns the error
     * that failed the read-back, if any.
     */
    private function finish(InvoiceDocument $document, MoloniClient $client, bool $announce = true): ?Throwable
    {
        $errorType = null;
        $errorMessage = null;
        $error = null;

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
            $error = $e;
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

        return $error;
    }

    /**
     * A failure before the insert. What may work later (a lost connection, an uncertain customer
     * or product write, which the resolvers find on the next attempt) is retried with backoff
     * until the row's last attempt. Giving up fails the row when nothing of it can exist in
     * Moloni (it is Pending, or this run's reconciliation found nothing); otherwise it is left
     * Unknown.
     */
    private function failBeforeInsert(InvoiceDocument $document, MoloniException $e, bool $absentInMoloni): void
    {
        $retryable = in_array($e->type, [FailureType::Transient, FailureType::Unknown], true);

        if ($retryable && $document->attempts < $this->tries) {
            throw $e;
        }

        $absent = $absentInMoloni || $document->status === DocumentStatus::Pending;
        $this->settle($document, $absent ? DocumentStatus::Failed : DocumentStatus::Unknown, $e->type->value, $this->message($e));
    }

    private function backToPending(InvoiceDocument $document): InvoiceDocument
    {
        $document->status = DocumentStatus::Pending;
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
