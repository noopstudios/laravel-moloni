<?php

namespace Noop\Moloni\Filament\Resources\InvoiceDocuments;

use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Noop\Moloni\Filament\Resources\InvoiceDocuments\Pages\ListInvoiceDocuments;
use Noop\Moloni\Filament\Resources\InvoiceDocuments\Pages\ViewInvoiceDocument;
use Noop\Moloni\Filament\Resources\InvoiceDocuments\Schemas\InvoiceDocumentInfolist;
use Noop\Moloni\Filament\Resources\InvoiceDocuments\Tables\InvoiceDocumentsTable;
use Noop\Moloni\Models\InvoiceDocument;

/**
 * The Moloni documents, read-only: they change only through the actions, which call the manager.
 * Access follows InvoiceDocumentPolicy.
 */
class InvoiceDocumentResource extends Resource
{
    protected static ?string $model = InvoiceDocument::class;

    protected static ?string $slug = 'moloni-documents';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $recordTitleAttribute = 'reference';

    public static function getModelLabel(): string
    {
        return __('Moloni document');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Moloni documents');
    }

    public static function infolist(Schema $schema): Schema
    {
        return InvoiceDocumentInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return InvoiceDocumentsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInvoiceDocuments::route('/'),
            'view' => ViewInvoiceDocument::route('/{record}'),
        ];
    }
}
