<?php

namespace Noop\Moloni;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Noop\Moloni\Enums\DocumentType;
use Noop\Moloni\Enums\FailureType;
use Noop\Moloni\Exceptions\MoloniException;
use Noop\Moloni\Http\MoloniConnector;

/**
 * Moloni's document endpoints.
 */
class MoloniClient
{
    public function __construct(private readonly MoloniConnector $connector) {}

    /**
     * Inserts a document and returns its Moloni id. A confirmation without a usable id is Unknown:
     * the document may exist.
     *
     * @param  array<string, mixed>  $payload
     */
    public function insert(DocumentType $type, array $payload): int
    {
        $result = $this->connector->write($this->endpoint($type).'/insert', $payload);
        $documentId = $result['document_id'] ?? null;

        if (! is_numeric($documentId) || (int) $documentId <= 0) {
            throw new MoloniException("Moloni confirmed the {$type->value} without returning its id.", FailureType::Unknown);
        }

        return (int) $documentId;
    }

    /**
     * A document of any type, from documents/getOne. Moloni answers an unknown id with some other
     * document or an empty list, so the returned id is checked.
     *
     * @return array<string, mixed>
     */
    public function document(int $documentId): array
    {
        $row = $this->connector->read('documents/getOne', ['document_id' => $documentId]);

        if (! is_numeric($row['document_id'] ?? null) || (int) $row['document_id'] !== $documentId) {
            throw new MoloniException("Moloni has no document {$documentId}.", FailureType::Validation);
        }

        /** @var array<string, mixed> $row */
        return $row;
    }

    /**
     * Documents of the type whose our_reference is exactly the reference, from the type's own
     * getAll (an exact, non-unique filter: every page is read and the match is checked again).
     * Rows whose reference is another string, or whose status is known and not 1 (drafts,
     * cancelled documents), are left out; a row missing either is kept, so callers fail closed.
     *
     * @return list<array<string, mixed>>
     */
    public function findByReference(DocumentType $type, string $reference): array
    {
        $rows = $this->connector->readAll($this->endpoint($type).'/getAll', ['our_reference' => $reference]);

        /** @var list<array<string, mixed>> */
        return array_values(array_filter($rows, fn (mixed $row) => is_array($row)
            && (! is_string($row['our_reference'] ?? null) || $row['our_reference'] === $reference)
            && (! is_numeric($row['status'] ?? null) || (int) $row['status'] === 1)));
    }

    /**
     * A fresh download link (Moloni signs it for a limited time). Never store it.
     */
    public function pdfLink(int $documentId): string
    {
        $result = $this->connector->read('documents/getPDFLink', ['document_id' => $documentId]);
        $url = $result['url'] ?? null;

        if (! is_string($url) || $url === '') {
            throw new MoloniException("Moloni has no PDF for the document {$documentId}; it may be a draft or not exist.", FailureType::Validation);
        }

        return $url;
    }

    /**
     * "{series}/{number}" (e.g. "GR/8") from a document row, or just the number without a series
     * name; null without a number.
     *
     * @param  array<string, mixed>  $row
     */
    public static function documentNumber(array $row): ?string
    {
        $number = $row['number'] ?? null;

        if (! is_scalar($number) || (string) $number === '') {
            return null;
        }

        $series = $row['document_set_name'] ?? null;

        return is_string($series) && $series !== '' ? "{$series}/{$number}" : (string) $number;
    }

    /**
     * A Moloni amount in euros as cents, rounded half up; null when it is not a number.
     */
    public static function cents(mixed $amount): ?int
    {
        if (! is_numeric($amount)) {
            return null;
        }

        return BigDecimal::of((string) $amount)->toScale(2, RoundingMode::HalfUp)->getUnscaledValue()->toInt();
    }

    private function endpoint(DocumentType $type): string
    {
        return match ($type) {
            DocumentType::Proforma => 'proFormaInvoices',
            DocumentType::Invoice => 'invoices',
            DocumentType::InvoiceReceipt => 'invoiceReceipts',
            DocumentType::Receipt => 'receipts',
            DocumentType::CreditNote => 'creditNotes',
        };
    }
}
