<?php

namespace App\Policies;

use App\Models\Item;
use App\Models\User;

/**
 * Only admins manage the item catalog. Items are never deleted: owned items
 * keep their definition, so an item is retired by taking it out of the shop.
 */
final class ItemPolicy
{
    public function viewAny(User $staff): bool
    {
        return $staff->isAdmin();
    }

    public function view(User $staff, Item $item): bool
    {
        return $staff->isAdmin();
    }

    public function create(User $staff): bool
    {
        return $staff->isAdmin();
    }

    public function update(User $staff, Item $item): bool
    {
        return $staff->isAdmin();
    }

    /**
     * Uploaded art goes live for every player, so one staff member publishes
     * it (hushfield.assets.publisher); until one is named, any admin may.
     */
    public function publishArt(User $staff, Item $item): bool
    {
        $publisher = config('hushfield.assets.publisher');

        return $staff->isAdmin() && (blank($publisher) || strcasecmp((string) $publisher, $staff->email) === 0);
    }

    public function delete(User $staff, Item $item): bool
    {
        return false;
    }

    public function deleteAny(User $staff): bool
    {
        return false;
    }
}
