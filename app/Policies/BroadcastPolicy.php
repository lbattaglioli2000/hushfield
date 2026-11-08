<?php

namespace App\Policies;

use App\Models\Broadcast;
use App\Models\User;

/**
 * Only admins put WHSH on the air: a broadcast plays to every set in town.
 */
final class BroadcastPolicy
{
    public function viewAny(User $staff): bool
    {
        return $staff->isAdmin();
    }

    public function create(User $staff): bool
    {
        return $staff->isAdmin();
    }

    public function delete(User $staff, ?Broadcast $broadcast = null): bool
    {
        return $staff->isAdmin();
    }
}
