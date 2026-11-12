<?php

namespace App\Policies;

use App\Models\BookCopy;
use App\Models\User;
use App\Policies\Concerns\DeniesPanelWrites;

/**
 * Staff see who holds which copies. Copies are given with the book's
 * "Give a copy" action (GiveBookCopy), never written in the panel directly.
 */
final class BookCopyPolicy
{
    use DeniesPanelWrites;

    public function viewAny(User $staff): bool
    {
        return $staff->isStaff();
    }

    public function view(User $staff, BookCopy $copy): bool
    {
        return $staff->isStaff();
    }
}
