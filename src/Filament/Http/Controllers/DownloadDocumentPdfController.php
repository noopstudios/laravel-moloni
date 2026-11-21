<?php

namespace Noop\Moloni\Filament\Http\Controllers;

use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Noop\Moloni\Exceptions\MoloniException;
use Noop\Moloni\Facades\Moloni;
use Noop\Moloni\Models\InvoiceDocument;
use Noop\Moloni\Policies\InvoiceDocumentPolicy;

/**
 * Redirects to the document's PDF, asking Moloni for the link on each request since Moloni's
 * links expire. What the manager refuses answers 422 with its message.
 */
class DownloadDocumentPdfController
{
    public function __invoke(InvoiceDocument $document): RedirectResponse
    {
        abort_unless(InvoiceDocumentPolicy::canManage(Filament::auth()->user()), 403);

        try {
            return redirect()->away(Moloni::pdfLink($document));
        } catch (MoloniException $e) {
            abort(422, $e->getMessage());
        }
    }
}
