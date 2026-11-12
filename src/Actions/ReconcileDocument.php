<?php

namespace Noop\Moloni\Actions;

use Illuminate\Support\Facades\Event;
use Noop\Moloni\Enums\DocumentStatus;
use Noop\Moloni\Events\DocumentFailed;
use Noop\Moloni\Events\DocumentIssued;
use Noop\Moloni\Models\InvoiceDocument;
use Noop\Moloni\MoloniClient;
use Noop\Moloni\Support\Vat;

/**
 * Looks in Moloni for a document whose insert may have been applied (status Issuing or Unknown)
 * before it is ever sent again.
 *
 * A Moloni document matches when it is closed, has the row's our_reference, series, customer and
 * total, and is not already recorded on another row (e.g. the document a reissue superseded).
 */
class ReconcileDocument
{
    public const AMBIGUOUS = 'ambiguous_reconciliation';

    public function __construct(private readonly MoloniClient $client) {}

    /**
     * True when the row is settled: it adopted the one match, or it stays Unknown because several
     * documents match. False when Moloni has no such document, so it may be issued. A failed read
     * throws, leaving the row as it was.
     */
    public function __invoke(InvoiceDocument $document): bool
    {
        $matches = array_values(array_filter(
            $this->client->findByReference($document->type, $document->reference),
            fn (array $row) => $this->matches($document, $row),
        ));

        if ($matches === []) {
            return false;
        }

        if (count($matches) > 1) {
            $document->fill([
                'status' => DocumentStatus::Unknown,
                'error_type' => self::AMBIGUOUS,
                'error_message' => count($matches)." documents in Moloni match {$document->reference}; check which one is this document's.",
            ])->save();
            Event::dispatch(new DocumentFailed($document));

            return true;
        }

        $document->fill([
            'status' => DocumentStatus::Issued,
            'document_id' => (int) $matches[0]['document_id'],
            'document_number' => MoloniClient::documentNumber($matches[0]),
            'issued_at' => now(),
            'error_type' => null,
            'error_message' => null,
        ])->save();
        Event::dispatch(new DocumentIssued($document));

        return true;
    }

    /**
     * @param  array<string, mixed>  $row  A closed document with our reference (see MoloniClient::findByReference()).
     */
    private function matches(InvoiceDocument $document, array $row): bool
    {
        return is_numeric($row['document_set_id'] ?? null)
            && (int) $row['document_set_id'] === $document->document_set_id
            && $this->sameCustomer($document, $row)
            && MoloniClient::cents($row['net_value'] ?? null) === $document->expected_total_cents
            && ! $this->recordedElsewhere($document, (int) $row['document_id']);
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
