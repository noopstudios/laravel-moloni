<?php

namespace Noop\Moloni\Actions;

use Brick\Math\BigDecimal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Noop\Moloni\Data\CreditLine;
use Noop\Moloni\Data\InvoiceData;
use Noop\Moloni\Data\InvoiceLine;
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

/**
 * Records a credit note on an issued invoice or invoice-receipt (or one superseded by a reissue
 * after it reached Moloni, where it is still valid), without calling Moloni. The
 * origin is locked and re-read, and so are the live credit notes already crediting it: each line
 * can be credited up to its quantity across all of them (Moloni tracks the same per line).
 *
 * The credit note claims at most the origin's open balance (what an invoice still owes; nothing
 * of an invoice-receipt, which is paid by nature): that is the association value Moloni accepts,
 * and the amount of its one link, so an unpaid invoice owes less and a paid one is unaffected.
 *
 * Its reference is the origin's followed by "/NC{n}", unique per credit note, so reconciliation
 * finds it by reference as it finds invoices.
 *
 * The locking reads can deadlock with receipts and other credit notes: when the package owns the
 * outermost transaction it is retried; inside a host transaction the deadlock propagates.
 */
class RequestCreditNote
{
    /** Tries of a transaction the package owns, when it deadlocks. */
    private const DEADLOCK_ATTEMPTS = 3;

    /** The width of invoice_documents.operation_key. */
    private const MAX_OPERATION_KEY_LENGTH = 255;

    /**
     * Returns the credit note and whether it was created by this call (false: the existing credit
     * note of the same idempotency key).
     *
     * @param  array<array-key, mixed>|null  $lines  list<CreditLine>; null credits everything still creditable.
     * @return array{0: InvoiceDocument, 1: bool}
     */
    public function __invoke(InvoiceDocument $origin, ?array $lines = null, ?string $idempotencyKey = null): array
    {
        if (! $origin->exists) {
            throw new InvalidArgumentException(Message::get('Only an issued invoice or invoice-receipt can be credited.'));
        }

        if ($idempotencyKey !== null && trim($idempotencyKey) === '') {
            throw new InvalidArgumentException(Message::get('A credit note idempotency key cannot be empty.'));
        }

        $requested = $lines === null ? null : $this->requested($lines);
        $operationKey = InvoiceDocument::operationKey(DocumentType::CreditNote, $origin, $idempotencyKey ?? (string) Str::ulid());

        if (strlen($operationKey) > self::MAX_OPERATION_KEY_LENGTH) {
            throw new InvalidArgumentException(Message::get('The credit note idempotency key is too long.'));
        }

        if ($idempotencyKey !== null) {
            // A plain read, before anything is computed: a repeated call gets its credit note even
            // when nothing is left to credit since, or the settings changed.
            $existing = $this->sameRequest(InvoiceDocument::query()->where('operation_key', $operationKey)->first(), $requested, $idempotencyKey);

            if ($existing !== null) {
                return [$existing, false];
            }
        }

        $settings = app(MoloniSettings::class);
        $companyId = $settings->companyIdOrNull()
            ?? throw new MoloniException(Message::get('No Moloni company is configured.'), FailureType::Validation);
        $documentSetId = $settings->document_sets[DocumentType::CreditNote->value]
            ?? throw new MoloniException(Message::get('No document set is configured for :type.', ['type' => DocumentType::CreditNote->getLabel()]), FailureType::Validation);

        $connection = $origin->getConnection();

        try {
            return $connection->transaction(function () use ($origin, $requested, $operationKey, $idempotencyKey, $companyId, $documentSetId) {
                $current = $this->lockOrigin($origin, $companyId);

                if ($idempotencyKey !== null) {
                    // Every credit note of this origin locks it first, so a same-key call that
                    // committed while this one waited is seen here by a locking read.
                    $existing = $this->sameRequest(InvoiceDocument::query()->where('operation_key', $operationKey)->lockForUpdate()->first(), $requested, $idempotencyKey);

                    if ($existing !== null) {
                        return [$existing, false];
                    }
                }

                $frozen = $this->frozenInvoice($current);
                $remaining = $this->remaining($current, $frozen);
                $credited = $requested ?? $this->everything($remaining);
                $this->assertCreditable($current, $frozen, $remaining, $credited);

                $invoice = $this->creditedInvoice($frozen, $credited);
                $total = $invoice->totalCents();
                $applied = min($total, $this->openBalance($current));

                $credit = new InvoiceDocument([
                    'type' => DocumentType::CreditNote,
                    'operation_key' => $operationKey,
                    'status' => DocumentStatus::Pending,
                    'company_id' => $companyId,
                    'document_set_id' => $documentSetId,
                    'reference' => $current->reference.'/NC'.($this->creditNotesSharingReference($current) + 1),
                    'currency' => $current->currency,
                    'expected_total_cents' => $total,
                    'customer_vat' => $current->customer_vat,
                    'customer_id' => $current->customer_id,
                    'customer_number' => $current->customer_number,
                    'payload' => [
                        'credit' => [
                            'origin_document_id' => $current->id,
                            'moloni_document_id' => (int) $current->document_id,
                            'lines' => array_map(fn (int $index, BigDecimal $quantity) => ['index' => $index, 'quantity' => $quantity->toFloat()], array_keys($credited), $credited),
                            'all' => $requested === null,
                            'applied_cents' => $applied,
                        ],
                        'invoice' => $invoice->toArray(),
                    ],
                ]);

                if ($current->invoiceable_type !== null && $current->invoiceable_id !== null) {
                    $credit->invoiceable_type = $current->invoiceable_type;
                    $credit->invoiceable_id = $current->invoiceable_id;
                }

                $credit->save();

                InvoiceDocumentLink::create([
                    'document_id' => $credit->id,
                    'associated_document_id' => $current->id,
                    'amount_cents' => $applied,
                ]);

                return [$credit, true];
            }, $connection->transactionLevel() === 0 ? self::DEADLOCK_ATTEMPTS : 1);
        } catch (UniqueConstraintViolationException $e) {
            if ($idempotencyKey === null) {
                throw $e;
            }

            $existing = InvoiceDocument::query()->where('operation_key', $operationKey)->lockForUpdate()->first();

            return [$this->sameRequest($existing, $requested, $idempotencyKey) ?? throw $e, false];
        }
    }

    /**
     * Checks, under lock, that a failed credit note still fits its origin: its lines are still
     * creditable and its claim still fits the open balance. Call inside a transaction.
     */
    public function assertStillFits(InvoiceDocument $credit): void
    {
        $data = self::creditOf($credit);

        try {
            $origin = InvoiceDocument::query()->whereKey($data['origin_document_id'])->firstOrFail();
            $current = $this->lockOrigin($origin, $credit->company_id ?? 0);
            $frozen = $this->frozenInvoice($current);
            $credited = [];

            foreach ($data['lines'] as $line) {
                $credited[$line['index']] = self::decimal($line['quantity']);
            }

            $this->assertCreditable($current, $frozen, $this->remaining($current, $frozen), $credited);
            $open = $this->openBalance($current);

            if ($data['applied_cents'] > $open) {
                throw new InvalidArgumentException(Message::get('Its claim of :applied is more than the :open still open on :document.', [
                    'applied' => self::euros($data['applied_cents']),
                    'open' => self::euros($open),
                    'document' => self::label($current),
                ]));
            }
        } catch (InvalidArgumentException $e) {
            throw new MoloniException(Message::get('The credit note :reference no longer fits its document: :reason', [
                'reference' => $credit->reference,
                'reason' => $e->getMessage(),
            ]), FailureType::Validation);
        }
    }

    /**
     * The frozen credit of a credit note.
     *
     * @return array{origin_document_id: int, moloni_document_id: int, lines: list<array{index: int, quantity: float}>, applied_cents: int}
     */
    public static function creditOf(InvoiceDocument $credit): array
    {
        $frozen = $credit->payload ?? throw new InvalidArgumentException("The credit note {$credit->getKey()} has no frozen payload.");
        $data = Payload::requiredArray($frozen, 'credit', 'credit note');
        $lines = [];

        foreach (Payload::requiredArray($data, 'lines', 'credit note') as $line) {
            if (! is_array($line)) {
                throw new InvalidArgumentException("The frozen credit note {$credit->getKey()} has an invalid line.");
            }

            $lines[] = [
                'index' => (int) Payload::required($line, 'index', 'credit line'),
                'quantity' => (float) Payload::required($line, 'quantity', 'credit line'),
            ];
        }

        return [
            'origin_document_id' => (int) Payload::required($data, 'origin_document_id', 'credit note'),
            'moloni_document_id' => (int) Payload::required($data, 'moloni_document_id', 'credit note'),
            'lines' => $lines,
            // Zero is a valid claim, which Payload::required() would read as missing.
            'applied_cents' => is_numeric($data['applied_cents'] ?? null) ? (int) $data['applied_cents'] : throw new InvalidArgumentException("The frozen credit note {$credit->getKey()} is missing \"applied_cents\"."),
        ];
    }

    /**
     * Validates the shape of the caller's lines.
     *
     * @param  array<array-key, mixed>  $lines
     * @return array<int, BigDecimal> Line index => quantity.
     */
    private function requested(array $lines): array
    {
        if ($lines === []) {
            throw new InvalidArgumentException(Message::get('Nothing to credit was requested.'));
        }

        $requested = [];

        foreach ($lines as $line) {
            if (! $line instanceof CreditLine) {
                throw new InvalidArgumentException(Message::get('Each line to credit must be a CreditLine.'));
            }

            if (isset($requested[$line->lineIndex])) {
                throw new InvalidArgumentException(Message::get('Line :line appears more than once in the credit note.', ['line' => $line->lineIndex]));
            }

            $requested[$line->lineIndex] = self::decimal($line->quantity);
        }

        ksort($requested);

        return $requested;
    }

    /**
     * The credit note already recorded under the idempotency key, when it is the same request:
     * the same lines and quantities, or again everything still creditable. Another request
     * under the key is refused.
     *
     * @param  array<int, BigDecimal>|null  $requested
     */
    private function sameRequest(?InvoiceDocument $existing, ?array $requested, string $idempotencyKey): ?InvoiceDocument
    {
        if ($existing === null) {
            return null;
        }

        $all = ($existing->payload['credit']['all'] ?? false) === true;
        $same = $requested === null ? $all : ! $all && $this->sameLines(self::creditOf($existing)['lines'], $requested);

        if (! $same) {
            throw new InvalidArgumentException(Message::get('The credit note key :key already belongs to another credit note; use a new key.', ['key' => $idempotencyKey]));
        }

        return $existing;
    }

    /**
     * @param  list<array{index: int, quantity: float}>  $stored
     * @param  array<int, BigDecimal>  $requested
     */
    private function sameLines(array $stored, array $requested): bool
    {
        if (count($stored) !== count($requested)) {
            return false;
        }

        foreach ($stored as $line) {
            if (! isset($requested[$line['index']]) || ! $requested[$line['index']]->isEqualTo(self::decimal($line['quantity']))) {
                return false;
            }
        }

        return true;
    }

    /**
     * The origin, locked and read under the lock, when it can be credited.
     */
    private function lockOrigin(InvoiceDocument $origin, int $companyId): InvoiceDocument
    {
        $current = InvoiceDocument::query()->whereKey($origin->getKey())->lockForUpdate()->first();

        // A superseded document with a Moloni id is still valid there (a reissue cancels nothing).
        if ($current === null
            || ! in_array($current->type, [DocumentType::Invoice, DocumentType::InvoiceReceipt], true)
            || ! in_array($current->status, [DocumentStatus::Issued, DocumentStatus::Superseded], true)
            || $current->document_id === null) {
            throw new InvalidArgumentException(Message::get('Only an issued invoice or invoice-receipt can be credited.'));
        }

        if ($current->company_id !== $companyId) {
            throw new InvalidArgumentException(Message::get('The document :document belongs to another Moloni company.', ['document' => self::label($current)]));
        }

        // Not while it is being cancelled (Moloni::cancel() holds its lock).
        IssueDocument::assertNotBeingCancelled($current);

        return $current;
    }

    private function frozenInvoice(InvoiceDocument $origin): InvoiceData
    {
        $invoice = $origin->payload['invoice'] ?? null;

        if (! is_array($invoice)) {
            throw new InvalidArgumentException(Message::get('The document :document has no recorded lines to credit.', ['document' => self::label($origin)]));
        }

        return InvoiceData::fromArray($invoice);
    }

    /**
     * What is still creditable on each line of the locked origin: its quantity less what its live
     * credit notes (locked here, in ascending id order) credit on it. They are read with FOR UPDATE
     * through a join on their links, as InvoiceDocument::outstandingCentsForUpdate() reads receipts:
     * a subquery on the links (whereHas) would read the snapshot MySQL's REPEATABLE READ took at
     * the host transaction's first read, and miss a credit note committed since.
     *
     * @return array<int, BigDecimal> Line index => quantity.
     */
    private function remaining(InvoiceDocument $origin, InvoiceData $frozen): array
    {
        $remaining = [];

        foreach ($frozen->lines as $index => $line) {
            $remaining[$index] = self::decimal($line->quantity);
        }

        $live = InvoiceDocument::query()
            ->select('invoice_documents.*')
            ->join('invoice_document_links as l', 'l.document_id', '=', 'invoice_documents.id')
            ->where('l.associated_document_id', $origin->getKey())
            ->where('invoice_documents.type', DocumentType::CreditNote)
            ->whereIn('invoice_documents.status', DocumentStatus::live())
            ->orderBy('invoice_documents.id')
            ->lockForUpdate()
            ->get()
            ->unique('id');

        foreach ($live as $credit) {
            foreach (self::creditOf($credit)['lines'] as $line) {
                if (isset($remaining[$line['index']])) {
                    $remaining[$line['index']] = $remaining[$line['index']]->minus(self::decimal($line['quantity']));
                }
            }
        }

        return $remaining;
    }

    /**
     * @param  array<int, BigDecimal>  $remaining
     * @return array<int, BigDecimal>
     */
    private function everything(array $remaining): array
    {
        return array_filter($remaining, fn (BigDecimal $quantity) => $quantity->isPositive());
    }

    /**
     * @param  array<int, BigDecimal>  $remaining
     * @param  array<int, BigDecimal>  $credited
     */
    private function assertCreditable(InvoiceDocument $origin, InvoiceData $frozen, array $remaining, array $credited): void
    {
        if ($credited === []) {
            throw new InvalidArgumentException(Message::get('Nothing is left to credit on :document.', ['document' => self::label($origin)]));
        }

        foreach ($credited as $index => $quantity) {
            if (! isset($frozen->lines[$index])) {
                throw new InvalidArgumentException(Message::get('The document :document has no line :line.', ['document' => self::label($origin), 'line' => $index]));
            }

            $left = $remaining[$index];

            if ($quantity->isGreaterThan($left)) {
                throw new InvalidArgumentException(Message::get('Line :line of :document can be credited by at most :quantity more.', [
                    'line' => $index,
                    'document' => self::label($origin),
                    'quantity' => (string) ($left->isNegative() ? BigDecimal::zero() : $left->strippedOfTrailingZeros()),
                ]));
            }
        }
    }

    /**
     * The credited lines, as the origin billed them, with the credited quantities.
     *
     * @param  array<int, BigDecimal>  $credited
     */
    private function creditedInvoice(InvoiceData $frozen, array $credited): InvoiceData
    {
        $lines = [];

        foreach ($credited as $index => $quantity) {
            $line = $frozen->lines[$index];
            $lines[] = new InvoiceLine($line->name, $quantity->toFloat(), $line->unitNetPrice, $line->taxRate, $line->exemptionReason, $line->reference, $line->productId, $line->summary);
        }

        return new InvoiceData($frozen->customer, $lines, $frozen->currency);
    }

    /**
     * What Moloni accepts as the claim on the locked origin: what an invoice still owes, nothing of
     * an invoice-receipt.
     */
    private function openBalance(InvoiceDocument $origin): int
    {
        return $origin->type === DocumentType::Invoice
            ? InvoiceDocument::outstandingCentsForUpdate([$origin])[$origin->id]
            : 0;
    }

    /**
     * Every credit note of a document with the origin's reference in its company, whatever its
     * status: a reissue or a conversion carries the same reference, and two credit notes with
     * the same reference could be taken for each other when reconciling. Read with FOR UPDATE
     * through joins (see remaining()), from the documents' (company_id, reference) index so only
     * theirs are locked, and counted here: PostgreSQL refuses FOR UPDATE on an aggregate.
     */
    private function creditNotesSharingReference(InvoiceDocument $origin): int
    {
        return $origin->getConnection()->table('invoice_documents', 'o')
            ->join('invoice_document_links as l', 'l.associated_document_id', '=', 'o.id')
            ->join('invoice_documents as s', 's.id', '=', 'l.document_id')
            ->where('s.type', DocumentType::CreditNote->value)
            ->where('o.reference', $origin->reference)
            ->where('o.company_id', $origin->company_id)
            ->whereIn('o.type', [DocumentType::Invoice->value, DocumentType::InvoiceReceipt->value])
            ->lockForUpdate()
            ->pluck('s.id')
            ->unique()
            ->count();
    }

    /**
     * A quantity as an exact decimal: the float's shortest round-trip form, as InvoiceLine reads it.
     */
    private static function decimal(float $value): BigDecimal
    {
        return BigDecimal::of(var_export($value, true));
    }

    private static function label(InvoiceDocument $document): string
    {
        return $document->document_number ?? $document->reference;
    }

    private static function euros(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
