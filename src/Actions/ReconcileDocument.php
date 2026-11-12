<?php

namespace Noop\Moloni\Actions;

use Illuminate\Support\Facades\Event;
use Noop\Moloni\Enums\DocumentStatus;
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
 */
class ReconcileDocument
{
    public const AMBIGUOUS = 'ambiguous_reconciliation';

    public function __construct(private readonly MoloniClient $client) {}

    /**
     * True when the row is settled (adopted, or left Unknown); false when Moloni has nothing that
     * could be this document. A failed read throws, leaving the row as it was.
     */
    public function __invoke(InvoiceDocument $document): bool
    {
        $blocking = array_values(array_filter(
            $this->client->findByReference($document->type, $document->reference),
            fn (array $row) => $this->blocks($document, $row),
        ));

        if ($blocking === []) {
            return false;
        }

        if (count($blocking) === 1 && $this->adoptable($document, $blocking[0])) {
            $document->fill([
                'status' => DocumentStatus::Issued,
                'document_id' => (int) $blocking[0]['document_id'],
                'document_number' => MoloniClient::documentNumber($blocking[0]),
                'issued_at' => now(),
                'error_type' => null,
                'error_message' => null,
            ])->save();
            Event::dispatch(new DocumentIssued($document));

            return true;
        }

        $document->fill([
            'status' => DocumentStatus::Unknown,
            'error_type' => self::AMBIGUOUS,
            'error_message' => Message::get('Moloni already has :count document(s) with the reference :reference in this series that cannot be confirmed as this one; check them in Moloni.', [
                'count' => count($blocking),
                'reference' => $document->reference,
            ]),
        ])->save();
        Event::dispatch(new DocumentFailed($document));

        return true;
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
