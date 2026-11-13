<?php

namespace Noop\Moloni\Filament\Resources\InvoiceDocuments\Tables;

use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Noop\Moloni\Enums\DocumentStatus;
use Noop\Moloni\Enums\DocumentType;
use Noop\Moloni\Filament\Actions\CancelDocumentAction;
use Noop\Moloni\Filament\Actions\ConvertProformaAction;
use Noop\Moloni\Filament\Actions\CreditNoteAction;
use Noop\Moloni\Filament\Actions\DownloadPdfAction;
use Noop\Moloni\Filament\Actions\ReconcileDocumentAction;
use Noop\Moloni\Filament\Actions\ReissueDocumentAction;
use Noop\Moloni\Filament\Actions\RetryDocumentAction;

class InvoiceDocumentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('type')
                    ->label(__('Document type')),
                TextColumn::make('document_number')
                    ->label(__('Document number'))
                    ->placeholder(__('Not issued yet'))
                    ->searchable(),
                TextColumn::make('reference')
                    ->label(__('Document reference'))
                    ->searchable(),
                TextColumn::make('customer_vat')
                    ->label(__('VAT number'))
                    ->placeholder(__('Final consumer'))
                    ->searchable(),
                TextColumn::make('expected_total_cents')
                    ->label(__('Document total'))
                    ->money(fn ($record) => $record->currency, divideBy: 100),
                TextColumn::make('status')
                    ->label(__('Document status'))
                    ->badge(),
                TextColumn::make('error_message')
                    ->label(__('Last error'))
                    ->limit(80)
                    ->wrap(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label(__('Document type'))
                    ->options(DocumentType::class),
                SelectFilter::make('status')
                    ->label(__('Document status'))
                    ->options(DocumentStatus::class),
            ])
            ->recordActions([
                ViewAction::make()->label(__('View document')),
                ActionGroup::make([
                    DownloadPdfAction::make(),
                    RetryDocumentAction::make(),
                    ReconcileDocumentAction::make(),
                    ConvertProformaAction::make(),
                    ReissueDocumentAction::make(),
                    CreditNoteAction::make(),
                    CancelDocumentAction::make(),
                ])->label(__('Moloni actions')),
            ]);
    }
}
