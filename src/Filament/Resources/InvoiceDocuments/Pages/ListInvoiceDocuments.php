<?php

namespace Noop\Moloni\Filament\Resources\InvoiceDocuments\Pages;

use Filament\Resources\Pages\ListRecords;
use Noop\Moloni\Filament\Resources\InvoiceDocuments\InvoiceDocumentResource;

class ListInvoiceDocuments extends ListRecords
{
    protected static string $resource = InvoiceDocumentResource::class;
}
