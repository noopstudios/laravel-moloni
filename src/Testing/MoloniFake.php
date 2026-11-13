<?php

namespace Noop\Moloni\Testing;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Noop\Moloni\Contracts\Invoiceable;
use Noop\Moloni\Data\CreditLine;
use Noop\Moloni\Data\PaymentData;
use Noop\Moloni\Enums\DocumentStatus;
use Noop\Moloni\Enums\DocumentType;
use Noop\Moloni\Enums\FailureType;
use Noop\Moloni\Events\DocumentFailed;
use Noop\Moloni\Events\DocumentIssued;
use Noop\Moloni\Models\InvoiceDocument;
use Noop\Moloni\Moloni;
use Noop\Moloni\Settings\MoloniSettings;
use PHPUnit\Framework\Assert;

/**
 * A test double for the Moloni manager, installed by Moloni::fake(). Requests are validated,
 * recorded and frozen as usual, then the row is issued on the spot: no HTTP, no queue, no
 * Moloni credentials or settings (it brings its own). The package events still fire.
 */
class MoloniFake extends Moloni
{
    /** Where the fake document ids and numbers start. */
    private const FIRST_DOCUMENT_ID = 1000;

    /** How many days of maturity dates a fake invoice accepts. */
    private const MAX_DUE_DAYS = 365;

    private int $lastDocumentId = self::FIRST_DOCUMENT_ID - 1;

    private ?FailureType $nextFailure = null;

    /** @var list<InvoiceDocument> */
    private array $issued = [];

    /**
     * Makes the next request end in the state the real flow leaves for this failure, firing
     * DocumentFailed as it does. Only that request is affected.
     */
    public function failNext(FailureType $type): static
    {
        $this->nextFailure = $type;

        return $this;
    }

    /**
     * @param  (Closure(InvoiceDocument): bool)|null  $callback
     */
    public function assertIssued(DocumentType $type, ?Closure $callback = null): void
    {
        $matching = array_filter($this->issued, fn (InvoiceDocument $document) => $document->type === $type
            && ($callback === null || $callback($document)));

        Assert::assertNotEmpty($matching, "No {$type->value} document was issued".($callback === null ? '.' : ' matching the callback.'));
    }

    public function assertNothingIssued(): void
    {
        Assert::assertCount(0, $this->issued, 'Documents were issued.');
    }

    public function assertIssuedCount(int $count): void
    {
        Assert::assertCount($count, $this->issued, "Expected {$count} issued documents.");
    }

    public function invoice(Invoiceable $invoiceable, int $dueInDays = 0): InvoiceDocument
    {
        $this->useSettings(null, $dueInDays);

        return parent::invoice($invoiceable, $dueInDays);
    }

    public function invoiceReceipt(Invoiceable $invoiceable, PaymentData $payment): InvoiceDocument
    {
        $this->useSettings($payment);

        return parent::invoiceReceipt($invoiceable, $payment);
    }

    public function proforma(Invoiceable $invoiceable, string $idempotencyKey): InvoiceDocument
    {
        $this->useSettings();

        return parent::proforma($invoiceable, $idempotencyKey);
    }

    public function convert(InvoiceDocument $proforma, ?PaymentData $payment = null, int $dueInDays = 0): InvoiceDocument
    {
        $this->useSettings($payment, $dueInDays, $proforma->company_id);

        return parent::convert($proforma, $payment, $dueInDays);
    }

    public function receipt(Model $owner, array $allocations, PaymentData $payment, ?string $idempotencyKey = null): InvoiceDocument
    {
        $this->useSettings($payment);

        return parent::receipt($owner, $allocations, $payment, $idempotencyKey);
    }

    /**
     * @param  list<CreditLine>|null  $lines
     */
    public function creditNote(InvoiceDocument $document, ?array $lines = null, ?string $idempotencyKey = null): InvoiceDocument
    {
        $this->useSettings();

        return parent::creditNote($document, $lines, $idempotencyKey);
    }

    public function reissue(InvoiceDocument $document, ?PaymentData $payment = null, ?int $dueInDays = null): InvoiceDocument
    {
        $this->useSettings($payment ?? $this->frozenPayment($document), $dueInDays ?? 0, $document->company_id);

        return parent::reissue($document, $payment, $dueInDays);
    }

    public function pdfLink(InvoiceDocument $document): string
    {
        return 'https://moloni.fake/documents/'.$document->document_id.'.pdf';
    }

    /**
     * Where the real manager queues the issuing job: issues the row here instead.
     */
    protected function dispatch(InvoiceDocument $document): void
    {
        $failure = $this->nextFailure;
        $this->nextFailure = null;

        $document->refresh();
        $document->attempts++;
        $document->insert_sent_at = now();

        if ($failure !== null) {
            $this->fail($document, $failure);

            return;
        }

        $this->lastDocumentId++;
        $customer = $document->payload['invoice']['customer']['key'] ?? null;
        $document->fill([
            // The same customer key always maps to the same fake customer, as receipts need.
            'customer_id' => is_string($customer) ? crc32($customer) : null,
            'customer_number' => is_string($customer) ? 'FAKE-'.$customer : null,
            'status' => DocumentStatus::Issued,
            'document_id' => $this->lastDocumentId,
            'document_number' => 'FAKE/'.$this->lastDocumentId,
            'issued_at' => now(),
            'error_type' => null,
            'error_message' => null,
        ])->save();

        $this->issued[] = $document;
        Event::dispatch(new DocumentIssued($document));
    }

    /**
     * Nothing is sent to Moloni, so there is nothing to cancel there.
     */
    protected function cancelInMoloni(InvoiceDocument $document, int $documentId, string $reason): void {}

    /**
     * What the issuing job leaves: an uncertain outcome is Unknown, anything else Failed (a
     * transient one after its retries ran out).
     */
    private function fail(InvoiceDocument $document, FailureType $type): void
    {
        $document->fill([
            'status' => $type === FailureType::Unknown ? DocumentStatus::Unknown : DocumentStatus::Failed,
            'error_type' => $type->value,
            'error_message' => __('Failure simulated by Moloni::fake().'),
        ])->save();

        Event::dispatch(new DocumentFailed($document));
    }

    private function frozenPayment(InvoiceDocument $document): ?PaymentData
    {
        $payment = $document->payload['payment'] ?? null;

        return is_array($payment) ? PaymentData::fromArray($payment) : null;
    }

    /**
     * Gives the request the configuration it validates against, so no Moloni catalog or
     * settings row is needed: a document set per type, the payment method and the term asked for.
     */
    private function useSettings(?PaymentData $payment = null, int $dueInDays = 0, ?int $companyId = null): void
    {
        MoloniSettings::fake([
            'company_id' => $companyId ?? 1,
            'document_sets' => array_fill_keys(array_column(DocumentType::cases(), 'value'), 1),
            'payment_methods' => $payment === null ? [] : [$payment->method => 1],
            'maturity_dates' => array_fill(0, max($dueInDays, self::MAX_DUE_DAYS) + 1, 1),
            'default_category_id' => null,
            'default_unit_id' => null,
            'default_product_id' => null,
            'tax_overrides' => [],
            'exemption_reasons' => [],
            'vies_unavailable' => 'charge',
            'fiscal_zone' => 'PT',
        ], loadMissingValues: false);
    }
}
