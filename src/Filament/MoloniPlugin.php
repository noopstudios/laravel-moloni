<?php

namespace Noop\Moloni\Filament;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Noop\Moloni\Filament\Http\Controllers\DownloadDocumentPdfController;
use Noop\Moloni\Filament\Pages\ManageMoloniSettings;
use Noop\Moloni\Filament\Resources\InvoiceDocuments\InvoiceDocumentResource;
use Noop\Moloni\Models\InvoiceDocument;
use Noop\Moloni\Policies\InvoiceDocumentPolicy;

/**
 * Adds the Moloni settings page and the documents to a panel: `->plugins([MoloniPlugin::make()])`.
 */
class MoloniPlugin implements Plugin
{
    public static function make(): static
    {
        return app(static::class);
    }

    public function getId(): string
    {
        return 'moloni';
    }

    public function register(Panel $panel): void
    {
        $panel
            ->resources([InvoiceDocumentResource::class])
            ->pages([ManageMoloniSettings::class])
            ->authenticatedRoutes(function (): void {
                Route::get('moloni/documents/{document}/pdf', DownloadDocumentPdfController::class)->name('moloni.documents.pdf');
            });

        // Explicit, so the documents stay guarded when the host guesses policy names its own way;
        // a policy the host registered for the model is kept.
        if (! array_key_exists(InvoiceDocument::class, Gate::policies())) {
            Gate::policy(InvoiceDocument::class, InvoiceDocumentPolicy::class);
        }
    }

    public function boot(Panel $panel): void {}
}
