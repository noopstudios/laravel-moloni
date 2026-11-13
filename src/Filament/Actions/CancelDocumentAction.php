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
 * Cancels an issued invoice or invoice-receipt in Moloni (Moloni::cancel()). When Moloni refuses,
 * its reason is shown and the credit note action (CreditNoteAction, in the same table or page)
 * opens in its place, for a full credit note instead.
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
            ->visible(fn (?InvoiceDocument $record): bool => self::isDocument($record, [DocumentType::Invoice, DocumentType::InvoiceReceipt], [DocumentStatus::Issued]))
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

                    if ($e instanceof MoloniException && self::refusedByMoloni($e, $record)) {
                        $this->offerCreditNote();
                    }

                    throw new Cancel;
                }
            });
    }

    /**
     * Whether Moloni itself refused, with the document still issued: only Moloni's refusal carries
     * its errors; local refusals (dependants, a lock) and uncertain outcomes do not.
     */
    private static function refusedByMoloni(MoloniException $e, InvoiceDocument $record): bool
    {
        return $e->type === FailureType::Validation
            && $e->errors !== []
            && $record->fresh()?->status === DocumentStatus::Issued;
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
