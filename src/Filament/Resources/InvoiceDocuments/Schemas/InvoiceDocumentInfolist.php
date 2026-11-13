<?php

namespace Noop\Moloni\Filament\Resources\InvoiceDocuments\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class InvoiceDocumentInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Moloni document'))
                ->columns(2)
                ->schema([
                    TextEntry::make('type')->label(__('Document type')),
                    TextEntry::make('status')->label(__('Document status'))->badge(),
                    TextEntry::make('document_number')->label(__('Document number'))->placeholder(__('Not issued yet')),
                    TextEntry::make('reference')->label(__('Document reference')),
                    TextEntry::make('customer_vat')->label(__('VAT number'))->placeholder(__('Final consumer')),
                    TextEntry::make('expected_total_cents')
                        ->label(__('Document total'))
                        ->money(fn ($record) => $record->currency, divideBy: 100),
                    TextEntry::make('issued_at')->label(__('Issued in Moloni at'))->dateTime()->placeholder(__('Not issued yet')),
                    TextEntry::make('due_at')->label(__('Document due date'))->date()->placeholder('-'),
                    TextEntry::make('attempts')->label(__('Issuing attempts')),
                    TextEntry::make('created_at')->label(__('Requested at'))->dateTime(),
                ]),
            Section::make(__('Last error'))
                ->visible(fn ($record): bool => filled($record?->error_message))
                ->schema([
                    TextEntry::make('error_type')->label(__('Last error type')),
                    TextEntry::make('error_message')->label(__('Last error message')),
                ]),
        ]);
    }
}
