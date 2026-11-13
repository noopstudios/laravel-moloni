<?php

namespace Noop\Moloni\Tests\Fixtures;

use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Livewire\Component;
use Noop\Moloni\Filament\Actions\CancelDocumentAction;
use Noop\Moloni\Models\InvoiceDocument;

/**
 * A host component outside the package's resource that uses the cancel action on its own.
 */
class DocumentActionsComponent extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    public InvoiceDocument $document;

    public function cancelAction(): Action
    {
        return CancelDocumentAction::make()->record($this->document);
    }

    public function render(): string
    {
        return '<div>{{ $this->cancelAction }}</div>';
    }
}
