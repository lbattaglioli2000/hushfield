<?php

namespace App\Policies;

use App\Models\Screening;
use App\Models\User;

/**
 * Only admins choose what the VFA shows: it plays to everyone in the room,
 * and the screen's own kill switch is admin-only too.
 */
final class ScreeningPolicy
{
    public function viewAny(User $staff): bool
    {
        return $staff->isAdmin();
    }

    public function create(User $staff): bool
    {
        return $staff->isAdmin();
    }

    public function delete(User $staff, ?Screening $screening = null): bool
    {
        return $staff->isAdmin();
    }
}
