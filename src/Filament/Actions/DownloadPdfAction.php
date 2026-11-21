<?php

namespace Noop\Moloni\Filament\Actions;

use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Noop\Moloni\Enums\DocumentStatus;
use Noop\Moloni\Filament\Actions\Concerns\CallsMoloni;
use Noop\Moloni\Models\InvoiceDocument;

/**
 * Opens the document's PDF in a new tab through the plugin's route, which asks Moloni for the
 * link on each click, since Moloni's links expire; the link is never stored or rendered.
 */
class DownloadPdfAction extends Action
{
    use CallsMoloni;

    public static function getDefaultName(): ?string
    {
        return 'downloadPdf';
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMoloniAction();

        $this->label(__('Download PDF'))
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->visible(fn (?InvoiceDocument $record): bool => $record?->status === DocumentStatus::Issued && $record->document_id !== null)
            ->url(fn (?InvoiceDocument $record): ?string => $record === null ? null : Filament::getCurrentPanel()?->route('moloni.documents.pdf', ['document' => $record]))
            ->openUrlInNewTab();
    }
}
