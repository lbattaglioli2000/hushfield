<?php

namespace App\Policies;

use App\Models\LibraryShelf;
use App\Models\User;

/**
 * Admins manage the library's categories. One with books on it can't be
 * deleted; the map may still have a shelf for it.
 */
final class LibraryShelfPolicy
{
    public function viewAny(User $staff): bool
    {
        return $staff->isAdmin();
    }

    public function view(User $staff, LibraryShelf $shelf): bool
    {
        return $staff->isAdmin();
    }

    public function create(User $staff): bool
    {
        return $staff->isAdmin();
    }

    public function update(User $staff, LibraryShelf $shelf): bool
    {
        return $staff->isAdmin();
    }

    public function delete(User $staff, LibraryShelf $shelf): bool
    {
        return $staff->isAdmin() && $shelf->books()->doesntExist();
    }

    public function deleteAny(User $staff): bool
    {
        return false;
    }
}
