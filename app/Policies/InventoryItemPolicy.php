<?php

namespace App\Policies;

use App\Models\InventoryItem;
use App\Models\User;
use App\Policies\Concerns\DeniesPanelWrites;

/**
 * Any staff member can see what a resident owns. Items are added through
 * UserPolicy::manageEconomy and the shop, never edited in the panel.
 */
final class InventoryItemPolicy
{
    use DeniesPanelWrites;

    public function viewAny(User $staff): bool
    {
        return $staff->isStaff();
    }

    public function view(User $staff, InventoryItem $owned): bool
    {
        return $staff->isStaff();
    }
}
