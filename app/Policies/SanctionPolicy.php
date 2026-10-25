<?php

namespace App\Policies;

use App\Models\Sanction;
use App\Models\User;

/**
 * Sanctions are issued through UserPolicy::sanction and never edited, only lifted.
 */
final class SanctionPolicy
{
    public function viewAny(User $staff): bool
    {
        return $staff->isStaff();
    }

    public function view(User $staff, Sanction $sanction): bool
    {
        return $staff->isStaff();
    }

    public function create(User $staff): bool
    {
        return false;
    }

    public function update(User $staff, Sanction $sanction): bool
    {
        return false;
    }

    public function delete(User $staff, Sanction $sanction): bool
    {
        return false;
    }

    public function deleteAny(User $staff): bool
    {
        return false;
    }

    public function lift(User $staff, Sanction $sanction): bool
    {
        return $staff->isStaff() && $sanction->isActive();
    }
}
