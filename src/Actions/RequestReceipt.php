<?php

namespace Noop\Moloni\Actions;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Noop\Moloni\Data\PaymentData;
use Noop\Moloni\Enums\DocumentStatus;
use Noop\Moloni\Enums\DocumentType;
use Noop\Moloni\Enums\FailureType;
use Noop\Moloni\Exceptions\MoloniException;
use Noop\Moloni\Models\InvoiceDocument;
use Noop\Moloni\Models\InvoiceDocumentLink;
use Noop\Moloni\Settings\MoloniSettings;
use Noop\Moloni\Support\Message;
use Noop\Moloni\Support\Payload;

/**
 * Records a receipt settling one or more issued invoices of one Moloni customer, without calling
 * Moloni. The invoices are locked (ascending id) and re-read, every allocation is checked against
 * what the invoice still owes, and the pending receipt and its links are written in the same
 * transaction: a pending receipt already counts against the invoices, so a concurrent request for
 * the same money fails here.
 *
 * The locking reads can deadlock with other receipts, even on unrelated invoices (InnoDB gap
 * locks). When the package owns the outermost transaction it is retried; inside a host
 * transaction the deadlock propagates, since the database already rolled that transaction back.
 */
class RequestReceipt
{
    /** The only currency the package issues in for now. */
    private const CURRENCY = 'EUR';

    /** Reconciliation reads every invoice in turn, within the issuing job's time budget. */
    public const MAX_INVOICES = 25;

    /** Tries of a transaction the package owns, when it deadlocks. */
    private const DEADLOCK_ATTEMPTS = 3;

    /** The width of invoice_documents.operation_key. */
    private const MAX_OPERATION_KEY_LENGTH = 255;

    /**
     * Returns the receipt and whether it was created by this call (false: the existing receipt of
     * the same idempotency key).
     *
     * @param  array<array-key, mixed>  $allocations  list<array{0: InvoiceDocument, 1: int}>: invoice, cents.
     * @return array{0: InvoiceDocument, 1: bool}
     */
    public function __invoke(Model $owner, array $allocations, PaymentData $payment, ?string $idempotencyKey = null): array
    {
        if (! $owner->exists || $owner->getKey() === null) {
            throw new InvalidArgumentException(Message::get('The owner of a receipt must be a saved model.'));
        }

        if ($idempotencyKey !== null && trim($idempotencyKey) === '') {
            throw new InvalidArgumentException(Message::get('A receipt idempotency key cannot be empty.'));
        }

        $requested = $this->allocations($allocations);
        $allocated = array_sum($requested);

        if ($allocated !== $payment->amountCents) {
            throw new InvalidArgumentException(Message::get('The amounts settled add up to :allocated, not to the payment of :paid.', [
                'allocated' => self::euros($allocated),
                'paid' => self::euros($payment->amountCents),
            ]));
        }

        $operationKey = InvoiceDocument::operationKey(DocumentType::Receipt, $owner, $idempotencyKey ?? (string) Str::ulid());

        if (strlen($operationKey) > self::MAX_OPERATION_KEY_LENGTH) {
            throw new InvalidArgumentException(Message::get('The receipt idempotency key is too long.'));
        }

        if ($idempotencyKey !== null) {
            // A plain read, before the settings: a repeated call gets its receipt even if the
            // settings changed since. A concurrent first call is caught by the unique key below.
            $existing = $this->sameRequest(InvoiceDocument::query()->where('operation_key', $operationKey)->first(), $requested, $payment, $idempotencyKey);

            if ($existing !== null) {
                return [$existing, false];
            }
        }

        $settings = app(MoloniSettings::class);
        $companyId = $settings->companyIdOrNull()
            ?? throw new MoloniException(Message::get('No Moloni company is configured.'), FailureType::Validation);
        $documentSetId = $settings->document_sets[DocumentType::Receipt->value]
            ?? throw new MoloniException(Message::get('No document set is configured for :type.', ['type' => DocumentType::Receipt->getLabel()]), FailureType::Validation);

        if (! isset($settings->payment_methods[$payment->method])) {
            throw new MoloniException(Message::get('The payment method :method is not configured.', ['method' => $payment->method]), FailureType::Validation);
        }

        // On the documents' connection: the owner may live on another one.
        $connection = (new InvoiceDocument)->getConnection();

        try {
            return $connection->transaction(function () use ($owner, $requested, $payment, $operationKey, $companyId, $documentSetId) {
                $invoices = $this->lockInvoices(array_keys($requested));
                $first = $invoices->firstOrFail();

                $receipt = new InvoiceDocument([
                    'type' => DocumentType::Receipt,
                    'operation_key' => $operationKey,
                    'status' => DocumentStatus::Pending,
                    'company_id' => $companyId,
                    'document_set_id' => $documentSetId,
                    // Local only: Moloni receipts take no our_reference.
                    'reference' => '#RC-'.Str::upper(substr(hash('sha256', $operationKey), 0, 12)),
                    'currency' => self::CURRENCY,
                    'expected_total_cents' => $payment->amountCents,
                    'customer_id' => $first->customer_id,
                    'customer_vat' => $first->customer_vat,
                    'customer_number' => $first->customer_number,
                    'payload' => [
                        'payment' => $payment->toArray(),
                        'allocations' => $invoices->map(fn (InvoiceDocument $invoice) => [
                            'invoice_document_id' => $invoice->id,
                            'moloni_document_id' => (int) $invoice->document_id,
                            'amount_cents' => $requested[$invoice->id],
                        ])->values()->all(),
                    ],
                ]);
                $receipt->invoiceable()->associate($owner);
                // Before the balance is read: a concurrent call with the same key that committed
                // while this one waited for the invoices fails here on the unique key, not on the
                // balance its links take. The row carries no links yet, so it settles nothing.
                $receipt->save();

                $this->assertSettleable($invoices, $requested, $companyId);

                foreach ($invoices as $invoice) {
                    InvoiceDocumentLink::create([
                        'document_id' => $receipt->id,
                        'associated_document_id' => $invoice->id,
                        'amount_cents' => $requested[$invoice->id],
                    ]);
                }

                return [$receipt, true];
            }, $connection->transactionLevel() === 0 ? self::DEADLOCK_ATTEMPTS : 1);
        } catch (UniqueConstraintViolationException $e) {
            if ($idempotencyKey === null) {
                throw $e;
            }

            // A call with the same key committed it first. A locking read sees it even under a
            // host transaction's older snapshot; the row exists, so no gap is locked.
            $existing = InvoiceDocument::query()->where('operation_key', $operationKey)->lockForUpdate()->first();

            return [$this->sameRequest($existing, $requested, $payment, $idempotencyKey) ?? throw $e, false];
        }
    }

    /**
     * Checks, under lock, that a failed receipt's allocations still fit what its invoices owe
     * (another receipt may have taken the money meanwhile). Call inside a transaction.
     */
    public function assertStillFits(InvoiceDocument $receipt): void
    {
        $requested = [];

        foreach (self::allocationsOf($receipt) as $allocation) {
            $requested[$allocation['invoice_document_id']] = $allocation['amount_cents'];
        }

        $companyId = $receipt->company_id ?? 0;

        try {
            $this->assertSettleable($this->lockInvoices(array_keys($requested)), $requested, $companyId);
        } catch (InvalidArgumentException $e) {
            throw new MoloniException(Message::get('The receipt :reference no longer fits what its invoices still owe: :reason', [
                'reference' => $receipt->reference,
                'reason' => $e->getMessage(),
            ]), FailureType::Validation);
        }
    }

    /**
     * The frozen allocations of a receipt.
     *
     * @return list<array{invoice_document_id: int, moloni_document_id: int, amount_cents: int}>
     */
    public static function allocationsOf(InvoiceDocument $receipt): array
    {
        $frozen = $receipt->payload ?? throw new InvalidArgumentException("The receipt {$receipt->getKey()} has no frozen payload.");
        $allocations = [];

        foreach (Payload::requiredArray($frozen, 'allocations', 'receipt') as $allocation) {
            if (! is_array($allocation)) {
                throw new InvalidArgumentException("The frozen receipt {$receipt->getKey()} has an invalid allocation.");
            }

            $allocations[] = [
                'invoice_document_id' => (int) Payload::required($allocation, 'invoice_document_id', 'receipt allocation'),
                'moloni_document_id' => (int) Payload::required($allocation, 'moloni_document_id', 'receipt allocation'),
                'amount_cents' => (int) Payload::required($allocation, 'amount_cents', 'receipt allocation'),
            ];
        }

        if ($allocations === []) {
            throw new InvalidArgumentException("The frozen receipt {$receipt->getKey()} settles no invoice.");
        }

        return $allocations;
    }

    /**
     * Validates the shape of the caller's allocations.
     *
     * @param  array<array-key, mixed>  $allocations
     * @return array<int, int> Invoice row id => cents.
     */
    private function allocations(array $allocations): array
    {
        if ($allocations === [] || ! array_is_list($allocations)) {
            throw new InvalidArgumentException(Message::get('A receipt needs a list of invoices, each with the amount it settles.'));
        }

        if (count($allocations) > self::MAX_INVOICES) {
            throw new InvalidArgumentException(Message::get('A receipt can settle at most :max invoices.', ['max' => self::MAX_INVOICES]));
        }

        $requested = [];

        foreach ($allocations as $allocation) {
            if (! is_array($allocation)
                || array_keys($allocation) !== [0, 1]
                || ! $allocation[0] instanceof InvoiceDocument
                || ! $allocation[0]->exists
                || ! is_int($allocation[1])) {
                throw new InvalidArgumentException(Message::get('Each receipt allocation must be a saved invoice and an amount in cents.'));
            }

            [$invoice, $cents] = $allocation;
            $label = self::label($invoice);

            if (isset($requested[$invoice->id])) {
                throw new InvalidArgumentException(Message::get('The invoice :invoice appears more than once in the receipt.', ['invoice' => $label]));
            }

            if ($cents <= 0) {
                throw new InvalidArgumentException(Message::get('The amount settled on :invoice must be greater than zero.', ['invoice' => $label]));
            }

            $requested[$invoice->id] = $cents;
        }

        return $requested;
    }

    /**
     * The receipt already recorded under the idempotency key, when it is the same request: the
     * same invoices and amounts, total and payment method. Another request under the key is refused.
     *
     * @param  array<int, int>  $requested
     */
    private function sameRequest(?InvoiceDocument $existing, array $requested, PaymentData $payment, string $idempotencyKey): ?InvoiceDocument
    {
        if ($existing === null) {
            return null;
        }

        $stored = [];

        foreach (self::allocationsOf($existing) as $allocation) {
            $stored[$allocation['invoice_document_id']] = $allocation['amount_cents'];
        }

        ksort($stored);
        ksort($requested);

        $storedMethod = $existing->payload['payment']['method'] ?? null;

        if ($stored !== $requested || $existing->expected_total_cents !== $payment->amountCents || $storedMethod !== $payment->method) {
            throw new InvalidArgumentException(Message::get('The receipt key :key already belongs to another receipt; use a new key.', ['key' => $idempotencyKey]));
        }

        return $existing;
    }

    /**
     * The invoices, locked in ascending id order and read under the lock.
     *
     * @param  list<int>  $ids
     * @return Collection<int, InvoiceDocument>
     */
    private function lockInvoices(array $ids): Collection
    {
        sort($ids);
        $invoices = InvoiceDocument::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get();

        if ($invoices->count() !== count($ids)) {
            throw new InvalidArgumentException(Message::get('Each receipt allocation must be a saved invoice and an amount in cents.'));
        }

        return $invoices;
    }

    /**
     * @param  Collection<int, InvoiceDocument>  $invoices  Locked.
     * @param  array<int, int>  $requested  Invoice row id => cents.
     */
    private function assertSettleable(Collection $invoices, array $requested, int $companyId): void
    {
        foreach ($invoices as $invoice) {
            $label = self::label($invoice);

            if ($invoice->type !== DocumentType::Invoice || $invoice->status !== DocumentStatus::Issued || $invoice->document_id === null) {
                throw new InvalidArgumentException(Message::get('Only issued invoices can be settled by a receipt, and :invoice is not one.', ['invoice' => $label]));
            }

            if ($invoice->company_id !== $companyId) {
                throw new InvalidArgumentException(Message::get('The invoice :invoice belongs to another Moloni company.', ['invoice' => $label]));
            }

            if ($invoice->currency !== self::CURRENCY) {
                throw new InvalidArgumentException(Message::get('Cannot issue in :currency: currency not supported yet.', ['currency' => $invoice->currency]));
            }

            if ($invoice->customer_id === null) {
                throw new InvalidArgumentException(Message::get('The invoice :invoice has no Moloni customer.', ['invoice' => $label]));
            }
        }

        $customers = $invoices->pluck('customer_id')->unique();

        if ($customers->count() !== 1) {
            throw new InvalidArgumentException(Message::get('The invoices settled by one receipt must belong to the same Moloni customer.'));
        }

        if ($invoices->count() > 1 && $invoices->contains(fn (InvoiceDocument $invoice) => $invoice->customer_vat === null)) {
            throw new InvalidArgumentException(Message::get('Invoices of a final consumer cannot be settled together by one receipt.'));
        }

        $owed = InvoiceDocument::outstandingCentsForUpdate($invoices);

        foreach ($invoices as $invoice) {
            $outstanding = $owed[$invoice->id];

            if ($requested[$invoice->id] > $outstanding) {
                throw new InvalidArgumentException(Message::get('The amount of :amount for :invoice is more than the :outstanding it still owes.', [
                    'amount' => self::euros($requested[$invoice->id]),
                    'invoice' => self::label($invoice),
                    'outstanding' => self::euros($outstanding),
                ]));
            }
        }
    }

    private static function label(InvoiceDocument $invoice): string
    {
        return $invoice->document_number ?? $invoice->reference;
    }

    private static function euros(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
