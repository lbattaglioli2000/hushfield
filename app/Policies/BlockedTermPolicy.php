<?php

namespace App\Policies;

use App\Models\BlockedTerm;
use App\Models\User;

/**
 * Only admins manage the blocked terms list.
 */
final class BlockedTermPolicy
{
    public function viewAny(User $staff): bool
    {
        return $staff->isAdmin();
    }

    public function view(User $staff, BlockedTerm $term): bool
    {
        return $staff->isAdmin();
    }

    public function create(User $staff): bool
    {
        return $staff->isAdmin();
    }

    public function update(User $staff, BlockedTerm $term): bool
    {
        return $staff->isAdmin();
    }

    public function delete(User $staff, BlockedTerm $term): bool
    {
        return $staff->isAdmin();
    }

    public function deleteAny(User $staff): bool
    {
        return $staff->isAdmin();
    }
}
