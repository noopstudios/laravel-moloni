<?php

namespace Noop\Moloni\Filament\Actions;

use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;
use Noop\Moloni\Actions\RequestCreditNote;
use Noop\Moloni\Enums\DocumentType;
use Noop\Moloni\Facades\Moloni;
use Noop\Moloni\Filament\Actions\Concerns\CallsMoloni;
use Noop\Moloni\Models\InvoiceDocument;

/**
 * A credit note for everything still creditable on an issued invoice or invoice-receipt, or on one
 * superseded by a reissue that is still valid in Moloni (Moloni::creditNote()). The key generated when the form opens makes a double submit return
 * the same credit note.
 */
class CreditNoteAction extends Action
{
    use CallsMoloni;

    public static function getDefaultName(): ?string
    {
        return 'creditNote';
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMoloniAction();

        $this->label(__('Issue a credit note'))
            ->icon(Heroicon::OutlinedReceiptRefund)
            ->color('danger')
            // Hidden once live credit notes cover every line: the call would only be refused.
            ->visible(fn (?InvoiceDocument $record): bool => self::isValidInMoloni($record, [DocumentType::Invoice, DocumentType::InvoiceReceipt])
                && $record !== null
                && app(RequestCreditNote::class)->hasCreditable($record))
            ->requiresConfirmation()
            ->modalHeading(__('Issue a credit note'))
            ->modalDescription(__('A credit note is issued in Moloni for everything still creditable on this document.'))
            ->modalSubmitActionLabel(__('Issue the credit note'))
            ->schema([
                Hidden::make('idempotency_key')
                    ->default(fn (): string => (string) Str::uuid())
                    ->required(),
            ])
            ->successNotificationTitle(__('A credit note was requested for this document.'))
            ->action(fn (InvoiceDocument $record, array $data) => $this->callMoloni(
                fn () => Moloni::creditNote($record, null, (string) $data['idempotency_key']),
            ));
    }
}
