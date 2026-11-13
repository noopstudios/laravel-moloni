<?php

namespace Noop\Moloni\Filament\Actions;

use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Noop\Moloni\Enums\DocumentStatus;
use Noop\Moloni\Facades\Moloni;
use Noop\Moloni\Filament\Actions\Concerns\CallsMoloni;
use Noop\Moloni\Models\InvoiceDocument;

/**
 * Sends a failed document again (Moloni::retry()).
 */
class RetryDocumentAction extends Action
{
    use CallsMoloni;

    public static function getDefaultName(): ?string
    {
        return 'retry';
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMoloniAction();

        $this->label(__('Send to Moloni again'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('warning')
            ->visible(fn (?InvoiceDocument $record): bool => $record?->status === DocumentStatus::Failed)
            ->successNotificationTitle(__('The document was sent to Moloni again.'))
            ->action(fn (InvoiceDocument $record) => $this->callMoloni(fn () => Moloni::retry($record)));
    }
}
