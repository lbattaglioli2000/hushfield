<?php

namespace App\Policies;

use App\Models\LedgerEntry;
use App\Models\User;
use App\Policies\Concerns\DeniesPanelWrites;

/**
 * Admins audit the ledger. It is append-only: coins move only through
 * UserPolicy::manageEconomy and the economy actions.
 */
final class LedgerEntryPolicy
{
    use DeniesPanelWrites;

    public function viewAny(User $staff): bool
    {
        return $staff->isAdmin();
    }

    public function view(User $staff, LedgerEntry $entry): bool
    {
        return $staff->isAdmin();
    }
}
