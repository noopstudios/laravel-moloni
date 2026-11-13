<?php

namespace Noop\Moloni\Filament\Actions\Concerns;

use Closure;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Cancel;
use InvalidArgumentException;
use LogicException;
use Noop\Moloni\Enums\DocumentStatus;
use Noop\Moloni\Enums\DocumentType;
use Noop\Moloni\Exceptions\MoloniException;
use Noop\Moloni\Models\InvoiceDocument;
use Noop\Moloni\Policies\InvoiceDocumentPolicy;
use Throwable;

/**
 * What every Moloni document action shares.
 */
trait CallsMoloni
{
    /**
     * The manager runs its own transactions and cancel() refuses to run inside one, so the action
     * never opens one, even on a panel with databaseTransactions(). Only users who manage Moloni
     * see it.
     */
    protected function setUpMoloniAction(): void
    {
        $this->databaseTransaction(false);
        $this->authorize(fn (): bool => InvoiceDocumentPolicy::canManage(Filament::auth()->user()));
    }

    /**
     * Runs a manager call. What the manager refuses is shown as a danger notification with its
     * message, and the action stops there.
     *
     * @template T
     *
     * @param  Closure(): T  $call
     * @return T
     */
    protected function callMoloni(Closure $call): mixed
    {
        try {
            return $call();
        } catch (MoloniException|InvalidArgumentException|LogicException $e) {
            $this->notifyRefusal($e);

            throw new Cancel;
        }
    }

    protected function notifyRefusal(Throwable $e): void
    {
        Notification::make()->danger()->title($e->getMessage())->send();
    }

    /**
     * @param  list<DocumentType>  $types
     * @param  list<DocumentStatus>  $statuses
     */
    protected static function isDocument(?InvoiceDocument $record, array $types, array $statuses): bool
    {
        return $record !== null
            && in_array($record->type, $types, true)
            && in_array($record->status, $statuses, true);
    }
}
