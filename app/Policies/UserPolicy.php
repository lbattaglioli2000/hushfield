<?php

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\DeniesPanelWrites;

/**
 * Staff (admins and moderators) moderate players. Only admins change staff roles.
 * Accounts are never created, edited, or deleted from the admin panel.
 */
final class UserPolicy
{
    use DeniesPanelWrites;

    public function viewAny(User $staff): bool
    {
        return $staff->isStaff();
    }

    public function view(User $staff, User $user): bool
    {
        return $staff->isStaff();
    }

    /**
     * Mute, ban, or warn. Staff cannot sanction themselves or other staff.
     */
    public function sanction(User $staff, User $user): bool
    {
        return $staff->isStaff() && ! $user->isStaff();
    }

    public function manageRole(User $staff, User $user): bool
    {
        return $staff->isAdmin() && $staff->isNot($user);
    }
}
