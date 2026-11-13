<?php

namespace Noop\Moloni\Filament\Actions;

use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Noop\Moloni\Enums\DocumentStatus;
use Noop\Moloni\Facades\Moloni;
use Noop\Moloni\Filament\Actions\Concerns\CallsMoloni;
use Noop\Moloni\Models\InvoiceDocument;

/**
 * Looks again in Moloni for a document awaiting confirmation (Moloni::reconcile()), of any type.
 */
class ReconcileDocumentAction extends Action
{
    use CallsMoloni;

    public static function getDefaultName(): ?string
    {
        return 'reconcile';
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMoloniAction();

        $this->label(__('Check in Moloni'))
            ->icon(Heroicon::OutlinedMagnifyingGlass)
            ->color('warning')
            ->visible(fn (?InvoiceDocument $record): bool => $record?->status === DocumentStatus::Unknown)
            ->requiresConfirmation()
            ->modalHeading(__('Check the document in Moloni'))
            ->modalDescription(__('Moloni is searched for this document: it is confirmed when Moloni has it, issued when Moloni has nothing that could be it, and left awaiting confirmation when the answer is still unclear.'))
            ->modalSubmitActionLabel(__('Check in Moloni'))
            ->successNotificationTitle(__('Moloni is being checked for this document again.'))
            ->action(fn (InvoiceDocument $record) => $this->callMoloni(fn () => Moloni::reconcile($record)));
    }
}
