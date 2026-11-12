<?php

namespace Noop\Moloni\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Noop\Moloni\Enums\DocumentStatus;
use Noop\Moloni\Jobs\IssueDocument;
use Noop\Moloni\Models\InvoiceDocument;
use Throwable;

/**
 * Dispatches again the documents whose job was lost: pending for more than five minutes, left
 * issuing for more than fifteen (the job died after marking it; it is reconciled before anything
 * is sent), or issued without their number and total read back (only read, never inserted).
 * Unknown documents are never touched automatically.
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
        $failed = 0;

        InvoiceDocument::query()
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $pending) => $pending
                    ->where('status', DocumentStatus::Pending)
                    ->where('updated_at', '<', now()->subMinutes(self::PENDING_MINUTES)))
                ->orWhere(fn (Builder $issuing) => $issuing
                    ->where('status', DocumentStatus::Issuing)
                    ->where('updated_at', '<', now()->subMinutes(self::ISSUING_MINUTES)))
                ->orWhere(fn (Builder $readBack) => $readBack
                    ->where('status', DocumentStatus::Issued)
                    ->where('error_type', IssueDocument::READBACK_FAILED)
                    ->whereNotNull('document_id')
                    ->where('updated_at', '<', now()->subMinutes(self::PENDING_MINUTES))))
            ->lazyById()
            ->each(function (InvoiceDocument $document) use (&$count, &$failed) {
                try {
                    IssueDocument::dispatch($document);
                    $count++;
                } catch (Throwable) {
                    // On a sync connection the job runs here; one document must not stop the others.
                    $failed++;
                }
            });

        $this->components->info(__('Dispatched :count Moloni documents again.', ['count' => $count]));

        if ($failed > 0) {
            $this->components->warn(__(':count Moloni documents failed while being dispatched again.', ['count' => $failed]));
        }

        return self::SUCCESS;
    }
}
