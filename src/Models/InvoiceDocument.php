<?php

namespace Noop\Moloni\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use LogicException;
use Noop\Moloni\Database\Factories\InvoiceDocumentFactory;
use Noop\Moloni\Enums\DocumentStatus;
use Noop\Moloni\Enums\DocumentType;
use Noop\Moloni\Support\Vat;

/**
 * A Moloni document, from the moment it is requested. Every request first creates a pending
 * row; the issuing job works from its frozen `payload`, never from the live invoiceable.
 *
 * Keys:
 * - `operation_key` identifies the request, so repeating it cannot create a second row:
 *   "{type}:{morph_class}:{id}:{sequence}" for invoices and invoice-receipts (see operationKey()).
 * - `active_key` allows one live invoice OR invoice-receipt per invoiceable:
 *   "{morph_class}:{id}:invoice" (see activeKeyFor()). It is derived on every save: set for a live
 *   invoice or invoice-receipt with an invoiceable, null otherwise, so a row that stops being live
 *   frees the slot and one that becomes live again takes it back (or fails if it is taken).
 *
 * @property int $id
 * @property string|null $invoiceable_type
 * @property int|string|null $invoiceable_id
 * @property DocumentType $type
 * @property string $operation_key
 * @property string|null $active_key
 * @property DocumentStatus $status
 * @property int|null $company_id
 * @property int|null $document_set_id
 * @property string $reference
 * @property string $currency
 * @property int $expected_total_cents
 * @property string|null $customer_vat Normalized with Vat::normalize().
 * @property string|null $customer_number
 * @property int|null $customer_id
 * @property int|null $document_id
 * @property string|null $document_number
 * @property Carbon|null $issued_at
 * @property Carbon|null $due_at
 * @property array<string, mixed>|null $vies
 * @property array<string, mixed>|null $payload The frozen request (InvoiceData and what the type needs).
 * @property string|null $error_type
 * @property string|null $error_message
 * @property int $attempts
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class InvoiceDocument extends Model
{
    /** @use HasFactory<InvoiceDocumentFactory> */
    use HasFactory;

    /** Everything the package writes; `active_key` is derived on save and never assigned. */
    protected $fillable = [
        'invoiceable_type',
        'invoiceable_id',
        'type',
        'operation_key',
        'status',
        'company_id',
        'document_set_id',
        'reference',
        'currency',
        'expected_total_cents',
        'customer_vat',
        'customer_number',
        'customer_id',
        'document_id',
        'document_number',
        'issued_at',
        'due_at',
        'vies',
        'payload',
        'error_type',
        'error_message',
        'attempts',
    ];

    protected $attributes = [
        'currency' => 'EUR',
        'attempts' => 0,
    ];

    protected function casts(): array
    {
        return [
            'type' => DocumentType::class,
            'status' => DocumentStatus::class,
            'company_id' => 'integer',
            'document_set_id' => 'integer',
            'expected_total_cents' => 'integer',
            'customer_id' => 'integer',
            'document_id' => 'integer',
            'issued_at' => 'datetime',
            'due_at' => 'date',
            'vies' => 'array',
            'payload' => 'array',
            'attempts' => 'integer',
        ];
    }

    /**
     * Derives `active_key` in save() itself rather than in a model event, so it also holds under
     * Event::fake(), saveQuietly() and withoutEvents().
     *
     * @param  array<string, mixed>  $options
     */
    public function save(array $options = []): bool
    {
        $this->active_key = $this->derivedActiveKey();

        return parent::save($options);
    }

    protected static function newFactory(): InvoiceDocumentFactory
    {
        return InvoiceDocumentFactory::new();
    }

    public static function activeKeyFor(Model $invoiceable): string
    {
        return $invoiceable->getMorphClass().':'.self::keyOf($invoiceable).':invoice';
    }

    public static function operationKey(DocumentType $type, Model $invoiceable, int|string $suffix): string
    {
        return $type->value.':'.$invoiceable->getMorphClass().':'.self::keyOf($invoiceable).':'.$suffix;
    }

    private static function keyOf(Model $invoiceable): string
    {
        $key = $invoiceable->getKey();

        if ($key === null) {
            throw new LogicException('Documents can only be keyed to a saved '.$invoiceable::class.'.');
        }

        return (string) $key;
    }

    private function derivedActiveKey(): ?string
    {
        $holdsSlot = in_array($this->type, [DocumentType::Invoice, DocumentType::InvoiceReceipt], true)
            // Read raw: a model not yet given a status has none, whatever the property type says.
            && $this->getAttribute('status') instanceof DocumentStatus
            && $this->status->isLive()
            && $this->invoiceable_type !== null
            && $this->invoiceable_id !== null;

        return $holdsSlot ? $this->invoiceable_type.':'.$this->invoiceable_id.':invoice' : null;
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function invoiceable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The documents this one settles, credits or converts.
     *
     * @return HasMany<InvoiceDocumentLink, $this>
     */
    public function links(): HasMany
    {
        return $this->hasMany(InvoiceDocumentLink::class, 'document_id');
    }

    /**
     * The links of documents that settle, credit or convert this one.
     *
     * @return HasMany<InvoiceDocumentLink, $this>
     */
    public function settledBy(): HasMany
    {
        return $this->hasMany(InvoiceDocumentLink::class, 'associated_document_id');
    }

    /**
     * What an invoice still owes: its total minus what live receipts and credit notes allocate
     * to it, never below zero. Zero for every other type.
     *
     * The invoice's own status is ignored: a pending, failed or voided invoice still reports an
     * amount. Callers allocating receipts or credit notes must require the invoice to be Issued.
     */
    public function outstandingCents(): int
    {
        if ($this->type !== DocumentType::Invoice) {
            return 0;
        }

        $settled = (int) self::settledCentsQuery($this->getConnection()->query())
            ->where('l.associated_document_id', $this->getKey())
            ->value('settled');

        return max(0, $this->expected_total_cents - $settled);
    }

    /**
     * Issued invoices of a VAT number that still owe something, oldest first. Final consumers
     * are never grouped by VAT, so their VAT matches nothing.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function openForVat(Builder $query, string $vat): void
    {
        $vat = Vat::normalize($vat);

        if (Vat::isFinalConsumer($vat)) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query
            ->where($query->qualifyColumn('type'), DocumentType::Invoice)
            ->where($query->qualifyColumn('status'), DocumentStatus::Issued)
            ->where($query->qualifyColumn('customer_vat'), $vat)
            ->where($query->qualifyColumn('expected_total_cents'), '>', fn (QueryBuilder $settled) => self::settledCentsQuery($settled)
                ->whereColumn('l.associated_document_id', $query->qualifyColumn('id')))
            ->orderBy($query->qualifyColumn('issued_at'))
            ->orderBy($query->qualifyColumn('id'));
    }

    /**
     * Sum of what live receipts and credit notes allocate, to be filtered by the settled invoice.
     */
    private static function settledCentsQuery(QueryBuilder $query): QueryBuilder
    {
        return $query
            ->from('invoice_document_links', 'l')
            ->join('invoice_documents as s', 's.id', '=', 'l.document_id')
            ->whereIn('s.type', array_map(
                fn (DocumentType $type) => $type->value,
                array_values(array_filter(DocumentType::cases(), fn (DocumentType $type) => $type->settlesDebt())),
            ))
            ->whereIn('s.status', array_map(fn (DocumentStatus $status) => $status->value, DocumentStatus::live()))
            // Unqualified: only the links table has amount_cents, and a raw alias would miss a table prefix.
            ->selectRaw('coalesce(sum(amount_cents), 0) as settled');
    }

    /**
     * @return Attribute<string|null, string|null>
     */
    protected function customerVat(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => Vat::normalize($value));
    }
}
