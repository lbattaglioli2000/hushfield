<?php

namespace App\Filament\Support;

use App\Models\User;
use Filament\FilamentManager;
use Filament\Models\Contracts\HasName;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Filament reads a `name` attribute for the user menu, avatar, and account
 * widget, but players only have a username. Stopgap until User implements
 * Filament's HasName contract, after which this class can be deleted.
 */
final class UsernameFilamentManager extends FilamentManager
{
    public function getUserName(Model|Authenticatable $user): string
    {
        if ($user instanceof User && ! $user instanceof HasName) {
            return $user->username;
        }

        return parent::getUserName($user);
    }
}
