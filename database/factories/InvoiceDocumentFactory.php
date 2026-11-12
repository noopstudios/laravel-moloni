<?php

namespace Noop\Moloni\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Noop\Moloni\Enums\DocumentStatus;
use Noop\Moloni\Enums\DocumentType;
use Noop\Moloni\Enums\FailureType;
use Noop\Moloni\Models\InvoiceDocument;

/**
 * @extends Factory<InvoiceDocument>
 */
class InvoiceDocumentFactory extends Factory
{
    protected $model = InvoiceDocument::class;

    /**
     * A pending invoice without an invoiceable.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => DocumentType::Invoice,
            'operation_key' => 'test:'.Str::uuid(),
            'status' => DocumentStatus::Pending,
            'reference' => 'REF-'.Str::upper(Str::random(8)),
            'currency' => 'EUR',
            'expected_total_cents' => 12300,
        ];
    }

    public function proforma(): static
    {
        return $this->state(['type' => DocumentType::Proforma]);
    }

    public function invoice(): static
    {
        return $this->state(['type' => DocumentType::Invoice]);
    }

    public function invoiceReceipt(): static
    {
        return $this->state(['type' => DocumentType::InvoiceReceipt]);
    }

    public function receipt(): static
    {
        return $this->state(['type' => DocumentType::Receipt]);
    }

    public function creditNote(): static
    {
        return $this->state(['type' => DocumentType::CreditNote]);
    }

    public function issued(): static
    {
        return $this->state(fn () => [
            'status' => DocumentStatus::Issued,
            'company_id' => 1,
            'document_set_id' => 1,
            'document_id' => $documentId = random_int(1, 2_000_000_000),
            'document_number' => 'A/'.$documentId,
            'issued_at' => now(),
            'attempts' => 1,
        ]);
    }

    public function failed(): static
    {
        return $this->state([
            'status' => DocumentStatus::Failed,
            'error_type' => FailureType::Validation->value,
            'error_message' => 'Rejected by Moloni.',
            'attempts' => 1,
        ]);
    }

    public function unknown(): static
    {
        return $this->state([
            'status' => DocumentStatus::Unknown,
            'error_type' => FailureType::Unknown->value,
            'error_message' => 'The response from Moloni was lost.',
            'attempts' => 1,
        ]);
    }
}
