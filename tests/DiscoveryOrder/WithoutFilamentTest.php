<?php

use Filament\FilamentServiceProvider;
use Illuminate\Support\Facades\Http;
use Livewire\LivewireServiceProvider;
use Noop\Moloni\Enums\DocumentStatus;
use Noop\Moloni\Facades\Moloni;
use Noop\Moloni\Tests\Fixtures\Order;

it('boots and issues a document with no Filament provider registered', function () {
    expect(app()->getProviders(FilamentServiceProvider::class))->toBe([])
        ->and(app()->getProviders(LivewireServiceProvider::class))->toBe([]);

    Http::preventStrayRequests();
    useIssueSettings();
    fakeMoloniIssuing();

    $document = Moloni::invoice(Order::create(['customer_vat' => '509999999']));

    expect($document->fresh()?->status)->toBe(DocumentStatus::Issued)
        ->and($document->fresh()?->document_number)->toBe('GR/8');
});
