<?php

namespace Noop\Moloni\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Noop\Moloni\Enums\DocumentStatus;
use Noop\Moloni\Jobs\IssueDocument;
use Noop\Moloni\Models\InvoiceDocument;

/**
 * Dispatches again the documents whose job was lost: pending for more than five minutes, or left
 * issuing for more than fifteen (the job died after marking it; it is reconciled before anything
 * is sent). Unknown documents are never touched automatically.
 */
class RecoverCommand extends Command
{
    private const PENDING_MINUTES = 5;

    private const ISSUING_MINUTES = 15;

    protected $signature = 'moloni:recover';

    protected $description = 'Dispatch again Moloni documents whose issuing job was lost';

    public function handle(): int
    {
        $count = 0;

        InvoiceDocument::query()
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $pending) => $pending
                    ->where('status', DocumentStatus::Pending)
                    ->where('updated_at', '<', now()->subMinutes(self::PENDING_MINUTES)))
                ->orWhere(fn (Builder $issuing) => $issuing
                    ->where('status', DocumentStatus::Issuing)
                    ->where('updated_at', '<', now()->subMinutes(self::ISSUING_MINUTES))))
            ->lazyById()
            ->each(function (InvoiceDocument $document) use (&$count) {
                IssueDocument::dispatch($document);
                $count++;
            });

        $this->components->info(__('Dispatched :count Moloni documents again.', ['count' => $count]));

        return self::SUCCESS;
    }
}
