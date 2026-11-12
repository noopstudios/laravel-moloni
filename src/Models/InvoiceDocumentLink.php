<?php

namespace Noop\Moloni\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One document acting on another: a receipt settling an invoice, a credit note crediting it,
 * an invoice converting a pro forma.
 *
 * @property int $id
 * @property int $document_id The acting document (receipt, credit note, invoice).
 * @property int $associated_document_id The document acted on.
 * @property int $amount_cents
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class InvoiceDocumentLink extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<InvoiceDocument, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(InvoiceDocument::class, 'document_id');
    }

    /**
     * @return BelongsTo<InvoiceDocument, $this>
     */
    public function associatedDocument(): BelongsTo
    {
        return $this->belongsTo(InvoiceDocument::class, 'associated_document_id');
    }
}
