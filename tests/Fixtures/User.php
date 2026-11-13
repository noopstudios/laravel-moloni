<?php

namespace Noop\Moloni\Tests\Fixtures;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * A host-app user who may enter every panel; what they may do in it comes from the Gate.
 *
 * @property int $id
 */
class User extends Authenticatable implements FilamentUser
{
    protected $table = 'test_users';

    protected $fillable = ['name', 'email', 'password'];

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }
}
