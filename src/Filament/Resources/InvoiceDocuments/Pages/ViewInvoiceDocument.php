<?php

namespace Noop\Moloni\Filament\Resources\InvoiceDocuments\Pages;

use Filament\Resources\Pages\ViewRecord;
use Noop\Moloni\Filament\Actions\CancelDocumentAction;
use Noop\Moloni\Filament\Actions\ConvertProformaAction;
use Noop\Moloni\Filament\Actions\CreditNoteAction;
use Noop\Moloni\Filament\Actions\DownloadPdfAction;
use Noop\Moloni\Filament\Actions\ReconcileDocumentAction;
use Noop\Moloni\Filament\Actions\ReissueDocumentAction;
use Noop\Moloni\Filament\Actions\RetryDocumentAction;
use Noop\Moloni\Filament\Resources\InvoiceDocuments\InvoiceDocumentResource;

class ViewInvoiceDocument extends ViewRecord
{
    protected static string $resource = InvoiceDocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DownloadPdfAction::make(),
            RetryDocumentAction::make(),
            ReconcileDocumentAction::make(),
            ConvertProformaAction::make(),
            ReissueDocumentAction::make(),
            CreditNoteAction::make(),
            CancelDocumentAction::make(),
        ];
    }
}
