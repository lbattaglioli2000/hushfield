<?php

namespace App\Policies;

use App\Models\Sanction;
use App\Models\User;
use App\Policies\Concerns\DeniesPanelWrites;

/**
 * Sanctions are issued through UserPolicy::sanction and never edited, only lifted.
 */
final class SanctionPolicy
{
    use DeniesPanelWrites;

    public function viewAny(User $staff): bool
    {
        return $staff->isStaff();
    }

    public function view(User $staff, Sanction $sanction): bool
    {
        return $staff->isStaff();
    }

    public function lift(User $staff, Sanction $sanction): bool
    {
        return $staff->isStaff() && $sanction->isActive();
    }
}
