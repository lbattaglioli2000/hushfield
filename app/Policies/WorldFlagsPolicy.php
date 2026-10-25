<?php

namespace App\Policies;

use App\Models\User;

/**
 * Only admins flip the world kill switches. Checked against the WorldFlags class.
 */
final class WorldFlagsPolicy
{
    public function viewAny(User $staff): bool
    {
        return $staff->isAdmin();
    }

    public function update(User $staff): bool
    {
        return $staff->isAdmin();
    }
}
