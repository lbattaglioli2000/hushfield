<?php

namespace App\Policies;

use App\Models\Book;
use App\Models\User;

/**
 * Admins write, publish and give out books. Moderators read every book, and
 * withdraw or restore one. Books are never deleted: withdrawn, at most.
 */
final class BookPolicy
{
    public function viewAny(User $staff): bool
    {
        return $staff->isStaff();
    }

    public function view(User $staff, Book $book): bool
    {
        return $staff->isStaff();
    }

    public function create(User $staff): bool
    {
        return $staff->isAdmin();
    }

    public function update(User $staff, Book $book): bool
    {
        return $staff->isAdmin();
    }

    public function publish(User $staff, Book $book): bool
    {
        return $staff->isAdmin();
    }

    public function giveCopy(User $staff, Book $book): bool
    {
        return $staff->isAdmin();
    }

    public function withdraw(User $staff, Book $book): bool
    {
        return $staff->isStaff();
    }

    public function delete(User $staff, Book $book): bool
    {
        return false;
    }

    public function deleteAny(User $staff): bool
    {
        return false;
    }
}
