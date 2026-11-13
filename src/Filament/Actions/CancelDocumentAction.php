<?php

namespace Noop\Moloni\Filament\Actions;

use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Support\Exceptions\Cancel;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use InvalidArgumentException;
use LogicException;
use Noop\Moloni\Enums\DocumentStatus;
use Noop\Moloni\Enums\DocumentType;
use Noop\Moloni\Enums\FailureType;
use Noop\Moloni\Exceptions\MoloniException;
use Noop\Moloni\Facades\Moloni;
use Noop\Moloni\Filament\Actions\Concerns\CallsMoloni;
use Noop\Moloni\Models\InvoiceDocument;

/**
 * Cancels an issued document of any type in Moloni, or an invoice superseded by a reissue that is
 * still valid there (Moloni::cancel()). A document others depend on is refused: cancel the receipt
 * or credit note first. When Moloni refuses an invoice or invoice-receipt, its reason is shown and
 * the credit note action (CreditNoteAction, in the same table or page) opens in its place, for a
 * full credit note instead.
 */
class CancelDocumentAction extends Action
{
    use CallsMoloni;

    public static function getDefaultName(): ?string
    {
        return 'cancel';
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMoloniAction();

        $this->label(__('Cancel in Moloni'))
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->visible(fn (?InvoiceDocument $record): bool => self::isValidInMoloni($record, DocumentType::cases()))
            ->requiresConfirmation()
            ->modalHeading(__('Cancel the document in Moloni'))
            ->modalDescription(__('Moloni annuls the document and it no longer counts. Moloni refuses while other documents depend on it.'))
            ->modalSubmitActionLabel(__('Cancel in Moloni'))
            ->schema([
                Textarea::make('reason')
                    ->label(__('Reason for cancelling'))
                    ->required()
                    ->maxLength(255),
            ])
            ->successNotificationTitle(__('The document was cancelled in Moloni.'))
            ->action(function (InvoiceDocument $record, array $data): void {
                try {
                    Moloni::cancel($record, (string) $data['reason']);
                } catch (MoloniException|InvalidArgumentException|LogicException $e) {
                    $this->notifyRefusal($e);

                    if ($e instanceof MoloniException && self::creditable($record) && self::refusedByMoloni($e, $record)) {
                        $this->offerCreditNote();
                    }

                    throw new Cancel;
                }
            });
    }

    /**
     * Whether a credit note can stand in for the cancellation: only an invoice or invoice-receipt.
     */
    private static function creditable(InvoiceDocument $record): bool
    {
        return in_array($record->type, [DocumentType::Invoice, DocumentType::InvoiceReceipt], true);
    }

    /**
     * Whether Moloni itself refused, with the document still issued (or superseded): only Moloni's
     * refusal carries its errors; local refusals (dependants, a lock) and uncertain outcomes do not.
     */
    private static function refusedByMoloni(MoloniException $e, InvoiceDocument $record): bool
    {
        return $e->type === FailureType::Validation
            && $e->errors !== []
            && in_array($record->fresh()?->status, [DocumentStatus::Issued, DocumentStatus::Superseded], true);
    }

    /**
     * Opens the credit note action in place of this one, when the table or page has it.
     */
    private function offerCreditNote(): void
    {
        $livewire = $this->getLivewire();

        if (! is_object($livewire) || ! method_exists($livewire, 'replaceMountedAction')) {
            return;
        }

        $context = data_get($livewire, 'mountedActions.'.$this->getNestingIndex().'.context', []);
        $livewire->replaceMountedAction(CreditNoteAction::getDefaultName() ?? 'creditNote', [], is_array($context) ? $context : []);

        // Stops here without closing the credit note action just opened.
        throw new Halt;
    }
}
