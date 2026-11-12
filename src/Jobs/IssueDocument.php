<?php

namespace Noop\Moloni\Jobs;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Noop\Moloni\Actions\ReconcileDocument;
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
use Noop\Moloni\Support\Payload;
use Throwable;

/**
 * Issues one document in Moloni from its frozen payload. A document must never be issued twice:
 *
 * - the row is saved as Issuing before the insert is sent, and its Moloni id right after;
 * - a row found Issuing or Unknown is first looked for in Moloni (ReconcileDocument);
 * - an insert whose outcome is uncertain leaves the row Unknown and is never retried here.
 *
 * Failures before the insert (customer, products, taxes) are retried with backoff when Moloni
 * may answer differently later, and fail the row otherwise.
 */
class IssueDocument implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const TOTAL_MISMATCH = 'total_mismatch';

    public const READBACK_FAILED = 'readback_failed';

    private const LOCK_SECONDS = 150;

    private const LOCKED_RETRY_SECONDS = 10;

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

    public function handle(
        MoloniClient $client,
        ReconcileDocument $reconcile,
        CustomerResolver $customers,
        TaxResolver $taxes,
        ProductResolver $products,
        MoloniSettings $settings,
    ): void {
        $lock = Cache::lock('moloni-doc-'.$this->document->getKey(), self::LOCK_SECONDS);

        if (! $lock->get()) {
            $this->release(self::LOCKED_RETRY_SECONDS);

            return;
        }

        try {
            $document = $this->document->fresh();

            if ($document !== null) {
                $this->issue($document, $client, $reconcile, $customers, $taxes, $products, $settings);
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * Called by the queue when the job gives up (an unexpected exception on the last attempt, a
     * timeout). Only a Pending row is marked failed: an Issuing or Unknown one may exist in Moloni.
     */
    public function failed(?Throwable $e): void
    {
        $document = $this->document->fresh();

        if ($document?->status !== DocumentStatus::Pending) {
            return;
        }

        if ($e instanceof MoloniException) {
            $this->markFailed($document, $e);

            return;
        }

        // Other exceptions (database errors, …) may carry data in their message.
        $this->settle($document, DocumentStatus::Failed, 'job_failed', 'The issuing job failed'.($e !== null ? ': '.class_basename($e) : '').'.');
    }

    private function issue(
        InvoiceDocument $document,
        MoloniClient $client,
        ReconcileDocument $reconcile,
        CustomerResolver $customers,
        TaxResolver $taxes,
        ProductResolver $products,
        MoloniSettings $settings,
    ): void {
        if (in_array($document->status, [DocumentStatus::Issued, DocumentStatus::Failed, DocumentStatus::Superseded, DocumentStatus::Voided], true)) {
            return;
        }

        if ($document->document_id !== null) {
            // Inserted, but the job stopped before finishing: never insert again.
            $this->finish($document, $client);

            return;
        }

        if (in_array($document->status, [DocumentStatus::Issuing, DocumentStatus::Unknown], true) && $reconcile($document)) {
            return;
        }

        try {
            $payload = $this->payload($document, $customers, $taxes, $products, $settings);
        } catch (MoloniException $e) {
            $this->failBeforeInsert($document, $e);

            return;
        }

        $document->status = DocumentStatus::Issuing;
        $document->attempts++;
        $document->save();

        try {
            $documentId = $client->insert($document->type, $payload);
        } catch (MoloniException $e) {
            match ($e->type) {
                // Only the token request can fail this way, before anything was sent.
                FailureType::Transient => $this->failBeforeInsert($this->backToPending($document), $e),
                FailureType::Unknown => $this->settle($document, DocumentStatus::Unknown, $e->type->value, $this->message($e)),
                default => $this->markFailed($document, $e),
            };

            return;
        } catch (Throwable $e) {
            $this->settle($document, DocumentStatus::Unknown, FailureType::Unknown->value, 'The document may have been issued: '.class_basename($e).'.');

            return;
        }

        $document->document_id = $documentId;
        $document->save();

        $this->finish($document, $client);
    }

    /**
     * Resolves the customer, products and taxes, and builds the insert payload from the frozen
     * request. Sets the customer on the row; it is saved with the Issuing status.
     *
     * @return array<string, mixed>
     */
    private function payload(
        InvoiceDocument $document,
        CustomerResolver $customers,
        TaxResolver $taxes,
        ProductResolver $products,
        MoloniSettings $settings,
    ): array {
        $frozen = $document->payload ?? throw new InvalidArgumentException("The document {$document->getKey()} has no frozen payload.");
        $invoice = InvoiceData::fromArray(Payload::requiredArray($frozen, 'invoice', 'document'));
        $payment = is_array($frozen['payment'] ?? null) ? PaymentData::fromArray($frozen['payment']) : null;
        $dueInDays = is_numeric($frozen['due_in_days'] ?? null) ? (int) $frozen['due_in_days'] : 0;

        $customer = $customers->resolve($invoice->customer);
        $document->customer_id = $customer->id;
        $document->customer_number = $customer->number;

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

        $today = Moloni::today();
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
     * Exactly the document total: Moloni does not check the payments against it.
     *
     * @return array<string, mixed>
     */
    private function payment(InvoiceDocument $document, ?PaymentData $payment, MoloniSettings $settings): array
    {
        if ($payment === null) {
            throw new InvalidArgumentException("The invoice-receipt {$document->getKey()} has no frozen payment.");
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
     * the read-back says; a total that differs from the expected one is flagged.
     */
    private function finish(InvoiceDocument $document, MoloniClient $client): void
    {
        $errorType = null;
        $errorMessage = null;

        try {
            $row = $client->document((int) $document->document_id);
            $document->document_number = MoloniClient::documentNumber($row);
            $total = MoloniClient::cents($row['net_value'] ?? null);

            if ($total !== $document->expected_total_cents) {
                $errorType = self::TOTAL_MISMATCH;
                $errorMessage = sprintf(
                    'Moloni computed a total of %s instead of %s.',
                    $total === null ? '?' : number_format($total / 100, 2, '.', ''),
                    number_format($document->expected_total_cents / 100, 2, '.', ''),
                );
            }
        } catch (Throwable $e) {
            $errorType = self::READBACK_FAILED;
            $errorMessage = $e instanceof MoloniException ? $this->message($e) : 'The document could not be read back: '.class_basename($e).'.';
        }

        $document->fill([
            'status' => DocumentStatus::Issued,
            'issued_at' => $document->issued_at ?? now(),
            'error_type' => $errorType,
            'error_message' => $errorMessage === null ? null : Str::limit($errorMessage, self::MAX_ERROR_LENGTH),
        ])->save();

        Event::dispatch(new DocumentIssued($document));
    }

    /**
     * Before the insert nothing exists in Moloni. What may work later (a lost connection, an
     * uncertain customer or product write, which the resolvers find on the next attempt) is
     * retried with backoff; the rest fails now.
     */
    private function failBeforeInsert(InvoiceDocument $document, MoloniException $e): void
    {
        $retryable = in_array($e->type, [FailureType::Transient, FailureType::Unknown], true);

        if ($retryable && $this->attempts() < $this->tries) {
            throw $e;
        }

        $this->markFailed($document, $e);
    }

    private function backToPending(InvoiceDocument $document): InvoiceDocument
    {
        $document->status = DocumentStatus::Pending;
        $document->save();

        return $document;
    }

    private function markFailed(InvoiceDocument $document, MoloniException $e): void
    {
        $this->settle($document, DocumentStatus::Failed, $e->type->value, $this->message($e));
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
