<?php

namespace Noop\Moloni\Actions;

use Illuminate\Support\Facades\Event;
use Noop\Moloni\Enums\DocumentStatus;
use Noop\Moloni\Enums\DocumentType;
use Noop\Moloni\Events\DocumentFailed;
use Noop\Moloni\Events\DocumentIssued;
use Noop\Moloni\Models\InvoiceDocument;
use Noop\Moloni\MoloniClient;
use Noop\Moloni\Support\Message;
use Noop\Moloni\Support\Vat;

/**
 * Looks in Moloni for the document before every insert, so a document whose earlier insert was
 * applied (a lost response, a reissue of an unconfirmed document) is never sent again.
 *
 * Blocking candidates: closed documents of the same type with exactly the row's our_reference, in
 * its series, not recorded on another local row (e.g. the document a reissue superseded). A row
 * whose series or id cannot be read blocks as well.
 *
 * - None: nothing of this document exists, so it may be issued.
 * - Exactly one, with the row's customer and total: adopted.
 * - Anything else: the row is left Unknown for a person to decide.
 *
 * A pro-forma whose insert was never sent (insert_sent_at null) is not looked for either: its
 * siblings share its reference, and it cannot exist in Moloni.
 *
 * Receipts carry no our_reference in Moloni, so a receipt whose insert was never sent
 * (insert_sent_at null) cannot exist there and is not looked for: it is issued, and Moloni's own
 * balance check refuses an over-allocation. Once sent, it is looked for on the invoices it settles: each invoice is read (documents/getOne) and its reverse associations give the closed
 * receipts in the row's series that settle it, less those recorded on another local row. None on
 * any invoice: issue. One receipt on every invoice, with the row's amount on each and the row's
 * total, which itself settles exactly the row's invoices and amounts for the row's customer:
 * adopted. Anything else (on some invoices only, other amounts, more than one, an entry
 * that cannot be read): Unknown.
 */
class ReconcileDocument
{
    public const AMBIGUOUS = 'ambiguous_reconciliation';

    /** Moloni's document_type_id of a receipt (3 is a credit note). */
    private const RECEIPT_TYPE_ID = 2;

    public function __construct(private readonly MoloniClient $client) {}

    /**
     * True when the row is settled (adopted, or left Unknown); false when Moloni has nothing that
     * could be this document. A failed read throws, leaving the row as it was.
     */
    public function __invoke(InvoiceDocument $document): bool
    {
        if ($document->type === DocumentType::Receipt) {
            return $this->reconcileReceipt($document);
        }

        // Pro-formas of one invoiceable share the reference, so one never sent cannot be told
        // apart from a sibling's document: it cannot exist in Moloni, and is issued.
        if ($document->type === DocumentType::Proforma && $document->insert_sent_at === null) {
            return false;
        }

        $blocking = array_values(array_filter(
            $this->client->findByReference($document->type, $document->reference),
            fn (array $row) => $this->blocks($document, $row),
        ));

        if ($blocking === []) {
            return false;
        }

        if (count($blocking) === 1 && $this->adoptable($document, $blocking[0])) {
            $this->adopt($document, (int) $blocking[0]['document_id'], MoloniClient::documentNumber($blocking[0]));

            return true;
        }

        $this->leaveUnknown($document, Message::get('Moloni already has :count document(s) with the reference :reference in this series that cannot be confirmed as this one; check them in Moloni.', [
            'count' => count($blocking),
            'reference' => $document->reference,
        ]));

        return true;
    }

    private function reconcileReceipt(InvoiceDocument $receipt): bool
    {
        if ($receipt->insert_sent_at === null) {
            return false;
        }

        $allocations = RequestReceipt::allocationsOf($receipt);
        $found = [];

        foreach ($allocations as $index => $allocation) {
            $found[$index] = $this->unrecordedReceipts($receipt, $this->client->document($allocation['moloni_document_id']));
        }

        $all = array_merge(...$found);

        if ($all === []) {
            return false;
        }

        $ids = array_values(array_unique(array_map(fn (array $candidate) => $candidate['id'], $all), SORT_REGULAR));
        $receiptId = count($ids) === 1 ? $ids[0] : null;

        if ($receiptId !== null && $this->receiptMatches($receipt, $allocations, $found)) {
            $row = $this->client->document($receiptId);

            if ($this->settlesExactly($receipt, $allocations, $row)) {
                $this->adopt($receipt, $receiptId, MoloniClient::documentNumber($row));

                return true;
            }
        }

        $this->leaveUnknown($receipt, Message::get('Moloni already has receipts on the invoices of :reference that cannot be confirmed as this one; check them in Moloni.', [
            'reference' => $receipt->reference,
        ]));

        return true;
    }

    /**
     * The closed receipts in the row's series that settle the invoice and are not recorded on
     * another local row, with the value they settle on it and their total. An entry that cannot
     * be read is kept with a null id, so it blocks.
     *
     * @param  array<string, mixed>  $invoice  As documents/getOne returns it.
     * @return list<array{id: int|null, value: int|null, total: int|null}>
     */
    private function unrecordedReceipts(InvoiceDocument $receipt, array $invoice): array
    {
        $unreadable = ['id' => null, 'value' => null, 'total' => null];
        $reverse = $invoice['reverse_associated_documents'] ?? null;

        if (! is_array($reverse)) {
            return [$unreadable];
        }

        $found = [];

        foreach ($reverse as $entry) {
            $settling = is_array($entry) ? ($entry['reverse_associated_document'] ?? null) : null;

            if (! is_array($entry) || ! is_array($settling)) {
                $found[] = $unreadable;

                continue;
            }

            $type = $settling['document_type_id'] ?? null;
            $status = $settling['status'] ?? null;
            $setId = $settling['document_set_id'] ?? null;

            if ((is_numeric($type) && (int) $type !== self::RECEIPT_TYPE_ID)
                || (is_numeric($status) && (int) $status !== 1)
                || (is_numeric($setId) && (int) $setId !== $receipt->document_set_id)) {
                continue;
            }

            $id = $entry['document_id'] ?? null;
            $settlingId = $settling['document_id'] ?? $id;
            $readable = is_numeric($type) && is_numeric($status) && is_numeric($setId)
                && is_numeric($id) && is_numeric($settlingId) && (int) $id === (int) $settlingId;

            if ($readable && $this->recordedElsewhere($receipt, (int) $id)) {
                continue;
            }

            $found[] = [
                'id' => $readable ? (int) $id : null,
                'value' => MoloniClient::cents($entry['value'] ?? null),
                'total' => MoloniClient::cents($settling['net_value'] ?? null),
            ];
        }

        return $found;
    }

    /**
     * Whether the one receipt found settles exactly the row's amount on every invoice, and has
     * the row's total.
     *
     * @param  list<array{invoice_document_id: int, moloni_document_id: int, amount_cents: int}>  $allocations
     * @param  array<int, list<array{id: int|null, value: int|null, total: int|null}>>  $found
     */
    private function receiptMatches(InvoiceDocument $receipt, array $allocations, array $found): bool
    {
        foreach ($allocations as $index => $allocation) {
            $candidates = $found[$index];

            if (count($candidates) !== 1
                || $candidates[0]['value'] !== $allocation['amount_cents']
                || $candidates[0]['total'] !== $receipt->expected_total_cents) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether the Moloni receipt settles exactly the row's invoices with the row's amounts (no
     * other invoice) and belongs to the row's customer.
     *
     * @param  list<array{invoice_document_id: int, moloni_document_id: int, amount_cents: int}>  $allocations
     * @param  array<string, mixed>  $row  The receipt, from documents/getOne.
     */
    private function settlesExactly(InvoiceDocument $receipt, array $allocations, array $row): bool
    {
        $associated = $row['associated_documents'] ?? null;

        if (! is_array($associated)
            || ! is_numeric($row['customer_id'] ?? null)
            || (int) $row['customer_id'] !== $receipt->customer_id) {
            return false;
        }

        $settles = [];

        foreach ($associated as $entry) {
            $id = is_array($entry) ? ($entry['associated_id'] ?? null) : null;

            if (! is_numeric($id) || isset($settles[(int) $id])) {
                return false;
            }

            $settles[(int) $id] = MoloniClient::cents($entry['value'] ?? null);
        }

        $expected = [];

        foreach ($allocations as $allocation) {
            $expected[$allocation['moloni_document_id']] = $allocation['amount_cents'];
        }

        ksort($settles);
        ksort($expected);

        return $settles === $expected;
    }

    private function adopt(InvoiceDocument $document, int $documentId, ?string $documentNumber): void
    {
        $document->fill([
            'status' => DocumentStatus::Issued,
            'document_id' => $documentId,
            'document_number' => $documentNumber,
            'issued_at' => now(),
            'error_type' => null,
            'error_message' => null,
        ])->save();
        Event::dispatch(new DocumentIssued($document));
    }

    private function leaveUnknown(InvoiceDocument $document, string $message): void
    {
        $document->fill([
            'status' => DocumentStatus::Unknown,
            'error_type' => self::AMBIGUOUS,
            'error_message' => $message,
        ])->save();
        Event::dispatch(new DocumentFailed($document));
    }

    /**
     * @param  array<string, mixed>  $row  A document of the type with our reference (see MoloniClient::findByReference()).
     */
    private function blocks(InvoiceDocument $document, array $row): bool
    {
        $setId = $row['document_set_id'] ?? null;
        $documentId = $row['document_id'] ?? null;

        // What cannot be read cannot be ruled out.
        if (! is_numeric($setId) || ! is_numeric($documentId)) {
            return true;
        }

        return (int) $setId === $document->document_set_id
            && ! $this->recordedElsewhere($document, (int) $documentId);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function adoptable(InvoiceDocument $document, array $row): bool
    {
        return is_numeric($row['document_id'] ?? null)
            && is_numeric($row['document_set_id'] ?? null)
            // Credit notes are looked for among every type: one whose type cannot be read blocks.
            && ($document->type !== DocumentType::CreditNote || is_numeric($row['document_type_id'] ?? null))
            && $this->sameCustomer($document, $row)
            && MoloniClient::cents($row['net_value'] ?? null) === $document->expected_total_cents;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function sameCustomer(InvoiceDocument $document, array $row): bool
    {
        $vat = Vat::normalize(is_scalar($row['entity_vat'] ?? null) ? (string) $row['entity_vat'] : null);

        if ($document->customer_vat !== null) {
            return $vat === $document->customer_vat;
        }

        // Final consumers share one VAT, so their own customer tells them apart when it is known.
        if ($document->customer_id !== null) {
            return is_numeric($row['customer_id'] ?? null) && (int) $row['customer_id'] === $document->customer_id;
        }

        return $vat === Vat::FINAL_CONSUMER;
    }

    private function recordedElsewhere(InvoiceDocument $document, int $documentId): bool
    {
        return InvoiceDocument::query()
            ->where('company_id', $document->company_id)
            ->where('document_id', $documentId)
            ->whereKeyNot($document->getKey())
            ->exists();
    }
}
