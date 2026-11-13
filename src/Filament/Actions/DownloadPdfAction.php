<?php

namespace Noop\Moloni\Filament\Actions;

use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Noop\Moloni\Enums\DocumentStatus;
use Noop\Moloni\Facades\Moloni;
use Noop\Moloni\Filament\Actions\Concerns\CallsMoloni;
use Noop\Moloni\Models\InvoiceDocument;

/**
 * Opens the document's PDF: the link is asked of Moloni on click (Moloni::pdfLink()), since
 * Moloni's links expire, and is never stored or rendered beforehand.
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
            ->action(function (InvoiceDocument $record): void {
                $this->redirect($this->callMoloni(fn () => Moloni::pdfLink($record)), navigate: false);
            });
    }
}
