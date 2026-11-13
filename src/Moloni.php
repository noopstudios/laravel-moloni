<?php

namespace Noop\Moloni;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use LogicException;
use Noop\Moloni\Actions\RequestCreditNote;
use Noop\Moloni\Actions\RequestReceipt;
use Noop\Moloni\Contracts\Invoiceable;
use Noop\Moloni\Data\CreditLine;
use Noop\Moloni\Data\InvoiceData;
use Noop\Moloni\Data\PaymentData;
use Noop\Moloni\Enums\DocumentStatus;
use Noop\Moloni\Enums\DocumentType;
use Noop\Moloni\Enums\FailureType;
use Noop\Moloni\Events\DocumentVoided;
use Noop\Moloni\Exceptions\MoloniException;
use Noop\Moloni\Jobs\IssueDocument;
use Noop\Moloni\Models\InvoiceDocument;
use Noop\Moloni\Models\InvoiceDocumentLink;
use Noop\Moloni\Settings\MoloniSettings;
use Noop\Moloni\Support\Message;
use Noop\Moloni\Support\Payload;
use Noop\Moloni\Support\Vat;
use Throwable;

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

    /** Tries of a transaction the package owns, when it deadlocks. */
    private const DEADLOCK_ATTEMPTS = 3;

    /** Documents and payments are dated in Portugal's calendar (mainland), as Moloni dates them. */
    public const TIMEZONE = 'Europe/Lisbon';

    public function __construct(
        private readonly MoloniClient $client,
        private readonly RequestReceipt $receipts,
        private readonly RequestCreditNote $credits,
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
            // A concurrent request with the same key created it first. A locking read: inside a host
            // transaction a plain read would miss a row committed after its snapshot.
            return InvoiceDocument::query()->where('operation_key', $operationKey)->lockForUpdate()->first() ?? throw $e;
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
     * Converting again while the conversion is live returns it, even with another payment or term;
     * a truly concurrent second call may instead be refused as being processed (Validation). A
     * pro-forma whose conversion failed or was voided can be converted again; one whose invoiceable
     * has another live invoice or invoice-receipt cannot. The document takes the pro-forma's
     * reference.
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
                    IssueDocument::assertNotBeingCancelled($current);
                    $conversion = $this->conversionOf($current);

                    if ($conversion !== null) {
                        return [$conversion, false];
                    }

                    if ($this->live($this->model($invoiceable)) !== null) {
                        throw new MoloniException(__('Another live document already exists for :reference.', ['reference' => $current->reference]), FailureType::Validation);
                    }

                    $quoted = InvoiceData::fromArray(Payload::requiredArray($current->payload ?? [], 'invoice', 'pro-forma'));
                    $attributes = $this->attributes($type, $invoiceable, $payment, $dueInDays, $quoted, $current->reference);
                    $attributes['payload']['converts'] = [
                        'proforma_document_id' => $current->id,
                        'moloni_document_id' => (int) $current->document_id,
                        'associate' => true,
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
     * At most RequestReceipt::MAX_INVOICES invoices per receipt. The invoices, and the receipts
     * already settling them, stay locked until the transaction ends, and the balance reads take
     * gap locks: concurrent receipts can deadlock, also on unrelated invoices. Called outside a
     * transaction, the package retries its own transaction. Called inside a host transaction, the
     * deadlock surfaces as Laravel's DeadlockException (or a QueryException) with the host
     * transaction already rolled back by the database: retry the whole unit of work. Keep such a
     * transaction short and request one receipt per transaction.
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
            $this->resendRequestedAgain($receipt);
        }

        return $receipt;
    }

    /**
     * A credit note on an issued invoice or invoice-receipt, or on one superseded by reissue() after
     * it reached Moloni (still valid there): $lines (a list of CreditLine, by the
     * position of the line in the document's request), or with null everything still creditable
     * on every line. Each line can be credited up to its quantity across all live credit notes.
     *
     * The credit note claims at most the document's open balance (what an invoice still owes;
     * nothing of an invoice-receipt): an unpaid invoice then owes that much less, a paid one is
     * unaffected. Its reference is the document's followed by "/NC{n}".
     *
     * Without $idempotencyKey every call records a new credit note. With it, a repeated call on
     * the same document with the same lines (or again null) returns the same credit note and sends
     * a failed one again, as retry() does; other lines under that key are refused. The document is
     * locked for the transaction, as for receipts, with the same deadlock rules (see receipt()).
     *
     * @param  list<CreditLine>|null  $lines
     *
     * @throws InvalidArgumentException When the document or the lines cannot be credited.
     * @throws MoloniException When the settings cannot issue a credit note (Validation).
     */
    public function creditNote(InvoiceDocument $document, ?array $lines = null, ?string $idempotencyKey = null): InvoiceDocument
    {
        [$credit, $created] = ($this->credits)($document, $lines, $idempotencyKey);

        if ($created) {
            $this->dispatch($credit);
        } elseif ($credit->status === DocumentStatus::Failed) {
            $this->resendRequestedAgain($credit);
        }

        return $credit;
    }

    /**
     * A failed receipt or credit note requested again under its key: sent again as retry() does,
     * unless another call or run got to it first, in which case the row is returned as it now is.
     * One that no longer fits its documents is still refused.
     */
    private function resendRequestedAgain(InvoiceDocument $receipt): void
    {
        $lock = IssueDocument::lockFor($receipt);

        if (! $lock->get()) {
            $receipt->refresh();

            return;
        }

        $connection = $receipt->getConnection();

        try {
            // It locks the receipt or credit note before its documents, the reverse of new ones:
            // a deadlock is retried when the package owns the transaction.
            $resent = $connection->transaction(function () use ($receipt) {
                $current = InvoiceDocument::query()->whereKey($receipt->getKey())->lockForUpdate()->firstOrFail();

                if ($current->status !== DocumentStatus::Failed) {
                    return false;
                }

                $this->resetForResend($current);

                return true;
            }, $connection->transactionLevel() === 0 ? self::DEADLOCK_ATTEMPTS : 1);
        } finally {
            $lock->release();
            $receipt->refresh();
        }

        if ($resent) {
            $this->dispatch($receipt);
        }
    }

    /**
     * Puts a locked failed row back to Pending with its attempts counted from zero; a receipt only
     * while its allocations still fit what its invoices owe, a credit note only while its lines and
     * claim still fit its document, a conversion only while its pro-forma is issued and not being
     * cancelled (Moloni would refuse the association anyway).
     */
    private function resetForResend(InvoiceDocument $current): void
    {
        $converts = $current->payload['converts'] ?? null;

        if (is_array($converts)) {
            // The conversion, then its pro-forma: the order reissue() locks them in.
            $proforma = InvoiceDocument::query()->whereKey((int) Payload::required($converts, 'proforma_document_id', 'conversion'))->lockForUpdate()->first();

            if ($proforma === null || $proforma->status !== DocumentStatus::Issued) {
                throw new MoloniException(__('The pro-forma converted by :reference is no longer issued.', ['reference' => $current->reference]), FailureType::Validation);
            }

            IssueDocument::assertNotBeingCancelled($proforma);
        }

        if ($current->type === DocumentType::Receipt) {
            $this->receipts->assertStillFits($current);
        }

        if ($current->type === DocumentType::CreditNote) {
            $this->credits->assertStillFits($current);
        }

        $current->fill(['status' => DocumentStatus::Pending, 'error_type' => null, 'error_message' => null, 'attempts' => 0])->save();
    }

    /**
     * Replaces an issued or unknown invoice or invoice-receipt with a new one, built from the
     * invoiceable's current data, with the same type and, unless given, the same payment and term.
     * The old row is marked superseded; nothing is cancelled in Moloni. An issued document that
     * live receipts or credit notes act on is refused (Validation): cancel them first, or correct
     * the document with a credit note instead of reissuing it.
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
                $connection = $document->getConnection();

                return $connection->transaction(function () use ($document, $invoiceable, $attributes) {
                    $old = InvoiceDocument::query()->whereKey($document->getKey())->lockForUpdate()->firstOrFail();
                    $this->assertReissuable($old);
                    $this->assertNothingDependsOn($old);
                    $old->status = DocumentStatus::Superseded;
                    $old->save();
                    $converts = $old->payload['converts'] ?? null;

                    if (is_array($converts)) {
                        // Not while the pro-forma is being cancelled (cancel() holds its lock).
                        $proforma = InvoiceDocument::query()->whereKey((int) Payload::required($converts, 'proforma_document_id', 'conversion'))->lockForUpdate()->first();

                        if ($proforma !== null) {
                            IssueDocument::assertNotBeingCancelled($proforma);
                        }

                        // The new document converts the same pro-forma, with its own link. Moloni only
                        // associates it while nothing of the old one can hold the pro-forma's balance:
                        // an old document with a Moloni id still does (nothing is cancelled there).
                        $attributes['payload']['converts'] = [
                            ...$converts,
                            'associate' => ($converts['associate'] ?? true) !== false && $old->document_id === null,
                        ];
                    }

                    return tap($this->create($document->type, $invoiceable, $attributes), $this->linkConversion(...));
                }, $connection->transactionLevel() === 0 ? self::DEADLOCK_ATTEMPTS : 1);
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
     * receipt is sent again only while its allocations still fit what its invoices owe, a credit
     * note only while its lines and claim still fit its document.
     */
    public function retry(InvoiceDocument $document): void
    {
        $this->whileHolding($document, function () use ($document) {
            $connection = $document->getConnection();

            try {
                // It locks the document before what it settles or converts, the reverse of new
                // receipts and credit notes: a deadlock is retried when the package owns the transaction.
                $connection->transaction(function () use ($document) {
                    // The caller's copy may be stale: the status is checked on the locked row.
                    $current = InvoiceDocument::query()->whereKey($document->getKey())->lockForUpdate()->firstOrFail();

                    if ($current->status !== DocumentStatus::Failed) {
                        throw new MoloniException(__('Only a failed document can be retried.'), FailureType::Validation);
                    }

                    $this->resetForResend($current);
                }, $connection->transactionLevel() === 0 ? self::DEADLOCK_ATTEMPTS : 1);
            } catch (UniqueConstraintViolationException) {
                throw new MoloniException(__('Another live document already exists for :reference.', ['reference' => $document->reference]), FailureType::Validation);
            } finally {
                $document->refresh();
            }
        });

        $this->dispatch($document);
    }

    /**
     * Looks again in Moloni for a document left unknown (its insert may or may not have landed):
     * the issuing job is dispatched for it, which adopts the document Moloni has, issues it when
     * Moloni has nothing that could be it, and leaves it unknown, with its error, when the answer
     * is still ambiguous or cannot be read.
     *
     * @throws MoloniException When the document is not unknown, or is being processed (Validation).
     */
    public function reconcile(InvoiceDocument $document): void
    {
        $this->whileHolding($document, function () use ($document) {
            // The caller's copy may be stale: the status is checked on the stored row.
            $document->refresh();

            if ($document->status !== DocumentStatus::Unknown) {
                throw new MoloniException(__('Only a document awaiting confirmation can be reconciled.'), FailureType::Validation);
            }
        });

        $this->dispatch($document);
    }

    /**
     * Cancels an issued document in Moloni and marks it voided: it no longer counts, frees its
     * invoiceable's live slot and releases what it settled. A document superseded by reissue()
     * after it reached Moloni (it has a Moloni id) is still valid there and can be cancelled too:
     * it becomes voided and DocumentVoided fires for it. Moloni accepts it only while no other
     * document depends on it, so a live receipt, credit note or conversion on it is refused here
     * without calling Moloni (cancel those first, or issue a credit note instead). A refusal from
     * Moloni is Validation with its reason, and nothing is issued in its place.
     *
     * Synchronous: Moloni is called while the document's lock is held, never inside a database
     * transaction (one that rolled back would forget a cancellation Moloni made). An uncertain
     * outcome is read back; when Moloni does not show it cancelled, the Unknown is rethrown with
     * the document unchanged, and cancelling again is safe.
     *
     * @throws InvalidArgumentException When the reason is empty.
     * @throws LogicException When called inside a database transaction.
     * @throws MoloniException When the document cannot be cancelled (Validation) or the outcome is uncertain (Unknown).
     */
    public function cancel(InvoiceDocument $document, string $reason): InvoiceDocument
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException(Message::get('A cancellation needs a reason.'));
        }

        if ($document->getConnection()->transactionLevel() > 0) {
            throw new LogicException('Moloni::cancel() calls Moloni, so it cannot run inside a database transaction.');
        }

        $this->whileHolding($document, function () use ($document, $reason) {
            // Writers of dependants refuse while this is held (see IssueDocument::assertNotBeingCancelled()).
            // Taken before the check below: a writer that found it free has locked the row first,
            // so the check waits for it to commit and sees its dependant.
            $cancelling = IssueDocument::cancelLockFor($document);

            if (! $cancelling->get()) {
                throw new MoloniException(__('The document :reference is being processed; try again in a moment.', ['reference' => $document->reference]), FailureType::Validation);
            }

            try {
                $this->assertCancellable($document);
                $documentId = (int) $document->document_id;
                $this->cancelInMoloni($document, $documentId, $reason);
                $this->markVoided($document, $reason);
            } finally {
                $cancelling->release();
            }
        });

        Event::dispatch(new DocumentVoided($document));

        return $document;
    }

    /**
     * Cancels the document in Moloni, or confirms Moloni already shows it cancelled: Moloni
     * refuses to cancel a cancelled document, so a refusal or an uncertain outcome is read back.
     */
    protected function cancelInMoloni(InvoiceDocument $document, int $documentId, string $reason): void
    {
        try {
            $this->client->cancel($documentId, $reason);
        } catch (MoloniException $e) {
            if (! in_array($e->type, [FailureType::Validation, FailureType::Unknown], true)) {
                throw $e;
            }

            if ($this->cancelledInMoloni($documentId)) {
                return;
            }

            if ($e->type === FailureType::Validation) {
                throw new MoloniException(__('Moloni refused to cancel :document: :reason', [
                    'document' => $document->document_number ?? $document->reference,
                    'reason' => self::reasonOf($e),
                ]), FailureType::Validation, $e->errors);
            }

            throw $e;
        }
    }

    /**
     * Marks the document voided once Moloni cancelled it, unless a dependant slipped in.
     */
    private function markVoided(InvoiceDocument $document, string $reason): void
    {
        $document->getConnection()->transaction(function () use ($document, $reason) {
            $current = InvoiceDocument::query()->whereKey($document->getKey())->lockForUpdate()->firstOrFail();
            $dependants = $this->liveDependants($current);

            // A safety net: writers of dependants refuse while this lock is held, but one that
            // checked just before may have committed during the call to Moloni.
            if ($dependants->isNotEmpty()) {
                throw new MoloniException(__('Moloni cancelled :document, but :dependants was requested for it meanwhile; check them in Moloni.', [
                    'document' => $current->document_number ?? $current->reference,
                    'dependants' => self::describe($dependants),
                ]), FailureType::Validation);
            }

            $current->fill([
                'status' => DocumentStatus::Voided,
                'payload' => [...($current->payload ?? []), 'void' => ['reason' => $reason, 'at' => now()->toIso8601String()]],
            ])->save();
        });
        $document->refresh();
    }

    /**
     * Checks the stored document (the caller's copy may be stale) on its locked row, in a short
     * transaction committed before Moloni is called, and refreshes the caller's copy.
     */
    private function assertCancellable(InvoiceDocument $document): void
    {
        try {
            $document->getConnection()->transaction(function () use ($document) {
                $current = InvoiceDocument::query()->whereKey($document->getKey())->lockForUpdate()->firstOrFail();
                $label = $current->document_number ?? $current->reference;

                // A superseded document is still valid in Moloni when its reissue came after it got there.
                if (! in_array($current->status, [DocumentStatus::Issued, DocumentStatus::Superseded], true) || $current->document_id === null) {
                    throw new MoloniException(__('Only an issued document can be cancelled.'), FailureType::Validation);
                }

                if ($current->company_id !== app(MoloniSettings::class)->companyIdOrNull()) {
                    throw new MoloniException(__('The document :document was issued for another Moloni company.', ['document' => $label]), FailureType::Validation);
                }

                $dependants = $this->liveDependants($current);

                if ($dependants->isNotEmpty()) {
                    throw new MoloniException(__('Cannot cancel :document while other documents depend on it: :dependants. Cancel them first or issue a credit note.', [
                        'document' => $label,
                        'dependants' => self::describe($dependants),
                    ]), FailureType::Validation);
                }
            });
        } finally {
            $document->refresh();
        }
    }

    /**
     * The live documents acting on this one: receipts and credit notes on it, documents converting
     * it. Call inside the transaction that locked $document, after the row lock.
     *
     * A plain read is enough for cancel(), which never runs inside an outer transaction: the
     * snapshot is taken at the first plain read after the row lock was granted, so it sees every dependant
     * committed by a writer that held that lock. Locking the dependants would take them after the
     * origin, the reverse of retry() and resends, and a deadlock there could strand a document
     * Moloni already cancelled. $forUpdate reads them with FOR UPDATE, for callers that may run
     * inside a host transaction (see assertNothingDependsOn()).
     *
     * @return Collection<int, InvoiceDocument>
     */
    private function liveDependants(InvoiceDocument $document, bool $forUpdate = false): Collection
    {
        return InvoiceDocument::query()
            ->select('invoice_documents.*')
            ->join('invoice_document_links as l', 'l.document_id', '=', 'invoice_documents.id')
            ->where('l.associated_document_id', $document->getKey())
            ->whereIn('invoice_documents.status', DocumentStatus::live())
            ->orderBy('invoice_documents.id')
            ->when($forUpdate, fn (Builder $query) => $query->lockForUpdate())
            ->get()
            ->unique('id')
            ->values();
    }

    /**
     * Refuses to reissue an issued document that live receipts or credit notes act on: they would
     * stay attached to the superseded row while the new document owes everything. Call inside the
     * transaction that locked $document. reissue() may run inside a host transaction, whose
     * snapshot can predate a dependant committed since, so they are read with FOR UPDATE, in the
     * origin-then-dependant order receipts and credit notes lock in.
     */
    private function assertNothingDependsOn(InvoiceDocument $document): void
    {
        if ($document->status !== DocumentStatus::Issued) {
            return;
        }

        $dependants = $this->liveDependants($document, forUpdate: true);

        if ($dependants->isNotEmpty()) {
            throw new MoloniException(__('Cannot reissue :document while other documents depend on it: :dependants. Cancel them first, or correct it with a credit note instead of reissuing.', [
                'document' => $document->document_number ?? $document->reference,
                'dependants' => self::describe($dependants),
            ]), FailureType::Validation);
        }
    }

    /**
     * @param  Collection<int, InvoiceDocument>  $documents
     */
    private static function describe(Collection $documents): string
    {
        return $documents->map(fn (InvoiceDocument $document) => $document->type->getLabel().' '.($document->document_number ?? $document->reference))->implode(', ');
    }

    /**
     * Whether Moloni shows the document cancelled (status 2); false when it cannot be read.
     */
    private function cancelledInMoloni(int $documentId): bool
    {
        try {
            $status = $this->client->document($documentId)['status'] ?? null;
        } catch (Throwable) {
            return false;
        }

        return is_numeric($status) && (int) $status === 2;
    }

    /**
     * Moloni's error descriptions (already redacted by the connector), or the exception's message.
     */
    private static function reasonOf(MoloniException $e): string
    {
        $details = collect($e->errors)
            ->map(fn (mixed $error) => is_array($error) ? ($error['description'] ?? $error['code'] ?? null) : $error)
            ->filter(fn (mixed $detail) => is_string($detail) && $detail !== '')
            ->unique()
            ->implode('; ');

        return $details === '' ? $e->getMessage() : $details;
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

        // Every read goes to the configured company, which would look up another company's id.
        if ($document->company_id !== app(MoloniSettings::class)->companyIdOrNull()) {
            throw new MoloniException(__('The document :document was issued for another Moloni company.', ['document' => $document->document_number ?? $document->reference]), FailureType::Validation);
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
            // A concurrent request took the live slot (or the same sequence) first. A locking read:
            // inside a host transaction a plain read would miss a row committed after its snapshot.
            return InvoiceDocument::query()->where('active_key', InvoiceDocument::activeKeyFor($model))->lockForUpdate()->first() ?? throw $e;
        }

        $this->dispatch($document);

        return $document;
    }

    /**
     * Validates the request against the settings and freezes it.
     *
     * @return array<string, mixed>
     */
    private function attributes(DocumentType $type, Invoiceable $invoiceable, ?PaymentData $payment, int $dueInDays, ?InvoiceData $invoice = null, ?string $reference = null): array
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

        if ($reference === null && trim($invoiceable->invoiceReference()) === '') {
            // Every such document would carry the reference "#", and reconciliation could not tell them apart.
            throw new MoloniException(__('The invoiceable has no reference to issue the document with.'), FailureType::Validation);
        }

        $vat = $invoice->customer->vat;

        return [
            'status' => DocumentStatus::Pending,
            'company_id' => $companyId,
            'document_set_id' => $documentSetId,
            // Exactly what is sent to Moloni as our_reference, and searched for when reconciling.
            'reference' => $reference ?? '#'.$invoiceable->invoiceReference(),
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

    /**
     * Queues the issuing job and refreshes the caller's copy: on a `sync` connection outside a
     * transaction the job has already run, and the caller gets the final state.
     */
    protected function dispatch(InvoiceDocument $document): void
    {
        IssueDocument::dispatch($document);
        $document->refresh();
    }

    /**
     * Today in Portugal's calendar, whatever the application's timezone. Documents are always
     * dated today: Moloni accepts a future date and then refuses earlier ones for that type in
     * the series, and refuses a date before the last one it holds.
     */
    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now(self::TIMEZONE)->startOfDay();
    }
}
