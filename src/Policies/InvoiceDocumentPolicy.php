<?php

namespace Noop\Moloni\Policies;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Auth\Authenticatable;
use Noop\Moloni\Models\InvoiceDocument;

/**
 * Documents are only read in the panel: whoever may manage Moloni sees them and runs their
 * actions; nobody creates, edits or deletes them there.
 */
class InvoiceDocumentPolicy
{
    /**
     * Whether the user has `moloni.permissions.manage` or `moloni.permissions.full_admin`. A null
     * or empty permission never grants.
     */
    public static function canManage(mixed $user): bool
    {
        if (! $user instanceof Authorizable) {
            return false;
        }

        foreach (['moloni.permissions.manage', 'moloni.permissions.full_admin'] as $key) {
            $permission = config($key);

            if (is_string($permission) && $permission !== '' && $user->can($permission)) {
                return true;
            }
        }

        return false;
    }

    public function viewAny(Authenticatable $user): bool
    {
        return self::canManage($user);
    }

    public function view(Authenticatable $user, InvoiceDocument $document): bool
    {
        return self::canManage($user);
    }

    public function create(Authenticatable $user): bool
    {
        return false;
    }

    public function update(Authenticatable $user, InvoiceDocument $document): bool
    {
        return false;
    }

    public function delete(Authenticatable $user, InvoiceDocument $document): bool
    {
        return false;
    }

    public function deleteAny(Authenticatable $user): bool
    {
        return false;
    }
}
