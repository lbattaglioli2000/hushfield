<?php

namespace App\Filament\Support;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The signed-in staff member, for passing to actions as the causer.
 */
final class Staff
{
    public static function current(): User
    {
        $user = auth()->user();

        if (! $user instanceof User || ! $user->isStaff()) {
            throw new AuthorizationException;
        }

        return $user;
    }
}
