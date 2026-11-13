<?php

namespace Noop\Moloni\Filament\Actions;

use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Noop\Moloni\Data\PaymentData;
use Noop\Moloni\Enums\DocumentStatus;
use Noop\Moloni\Enums\DocumentType;
use Noop\Moloni\Facades\Moloni;
use Noop\Moloni\Filament\Actions\Concerns\CallsMoloni;
use Noop\Moloni\Models\InvoiceDocument;
use Noop\Moloni\Settings\MoloniSettings;

/**
 * Converts an issued pro-forma (Moloni::convert()) into an invoice due in one of the configured
 * terms, or an invoice-receipt paid today, in full, with one of the configured payment methods.
 */
class ConvertProformaAction extends Action
{
    use CallsMoloni;

    public static function getDefaultName(): ?string
    {
        return 'convert';
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMoloniAction();

        $this->label(__('Convert the pro-forma'))
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->color('primary')
            ->visible(fn (?InvoiceDocument $record): bool => self::isDocument($record, [DocumentType::Proforma], [DocumentStatus::Issued]))
            ->modalHeading(__('Convert the pro-forma'))
            ->modalSubmitActionLabel(__('Convert the pro-forma'))
            ->schema([
                Radio::make('type')
                    ->label(__('Convert into'))
                    ->options([
                        DocumentType::Invoice->value => DocumentType::Invoice->getLabel(),
                        DocumentType::InvoiceReceipt->value => DocumentType::InvoiceReceipt->getLabel(),
                    ])
                    ->default(DocumentType::Invoice->value)
                    ->required()
                    ->live(),
                Select::make('due_in_days')
                    ->label(__('Invoice payment term'))
                    ->options(fn (): array => collect(array_keys(app(MoloniSettings::class)->maturity_dates))
                        ->mapWithKeys(fn (int|string $days) => [$days => __('Due in :days days', ['days' => $days])])
                        ->all())
                    ->default(0)
                    ->required()
                    ->visible(fn (Get $get): bool => $get('type') === DocumentType::Invoice->value),
                Select::make('payment_method')
                    ->label(__('Payment method used'))
                    ->options(fn (): array => collect(array_keys(app(MoloniSettings::class)->payment_methods))
                        ->mapWithKeys(fn (int|string $method) => [$method => $method])
                        ->all())
                    ->required()
                    ->visible(fn (Get $get): bool => $get('type') === DocumentType::InvoiceReceipt->value),
            ])
            ->successNotificationTitle(__('The pro-forma is being converted.'))
            ->action(function (InvoiceDocument $record, array $data): void {
                $this->callMoloni(fn () => $data['type'] === DocumentType::InvoiceReceipt->value
                    ? Moloni::convert($record, new PaymentData((string) $data['payment_method'], now(), $record->expected_total_cents))
                    : Moloni::convert($record, dueInDays: (int) $data['due_in_days']));
            });
    }
}
