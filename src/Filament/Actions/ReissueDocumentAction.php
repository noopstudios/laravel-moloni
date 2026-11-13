<?php

namespace Noop\Moloni\Filament\Actions;

use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Support\Icons\Heroicon;
use Noop\Moloni\Enums\DocumentStatus;
use Noop\Moloni\Enums\DocumentType;
use Noop\Moloni\Facades\Moloni;
use Noop\Moloni\Filament\Actions\Concerns\CallsMoloni;
use Noop\Moloni\Models\InvoiceDocument;

/**
 * Replaces an issued or unconfirmed invoice or invoice-receipt with a new one built from its
 * origin's current data (Moloni::reissue()). An issued document stays in Moloni, so replacing it
 * needs a reason, kept on the superseded row.
 */
class ReissueDocumentAction extends Action
{
    use CallsMoloni;

    public static function getDefaultName(): ?string
    {
        return 'reissue';
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMoloniAction();

        $this->label(__('Reissue the document'))
            ->icon(Heroicon::OutlinedDocumentDuplicate)
            ->color('warning')
            ->visible(fn (?InvoiceDocument $record): bool => self::isDocument($record, [DocumentType::Invoice, DocumentType::InvoiceReceipt], [DocumentStatus::Issued, DocumentStatus::Unknown])
                && $record?->invoiceable_type !== null)
            ->requiresConfirmation()
            ->modalHeading(__('Reissue the document'))
            ->modalDescription(fn (InvoiceDocument $record): string => $record->status === DocumentStatus::Issued
                ? __('A new document is issued from the current data of its origin. This one is not cancelled in Moloni: it stays issued there.')
                : __('A new document is issued from the current data of its origin, in place of this unconfirmed one.'))
            ->modalSubmitActionLabel(__('Reissue the document'))
            ->schema(fn (?InvoiceDocument $record): array => $record?->status === DocumentStatus::Issued ? [
                Textarea::make('reason')
                    ->label(__('Reason for reissuing'))
                    ->required()
                    ->maxLength(255),
            ] : [])
            ->successNotificationTitle(__('A new document was requested in place of this one.'))
            ->action(function (InvoiceDocument $record, array $data): void {
                $wasIssued = $record->status === DocumentStatus::Issued;
                $this->callMoloni(fn () => Moloni::reissue($record));

                if ($wasIssued && is_string($data['reason'] ?? null)) {
                    $record->refresh();
                    $record->payload = [...($record->payload ?? []), 'reissue' => ['reason' => $data['reason'], 'at' => now()->toIso8601String()]];
                    $record->save();
                }
            });
    }
}
