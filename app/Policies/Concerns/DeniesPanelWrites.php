<?php

namespace App\Policies\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * For models staff only read in the panel. Filament allows any ability whose
 * policy method is missing, so the write abilities are denied here explicitly.
 */
trait DeniesPanelWrites
{
    public function create(User $staff): bool
    {
        return false;
    }

    public function update(User $staff, Model $record): bool
    {
        return false;
    }

    public function delete(User $staff, Model $record): bool
    {
        return false;
    }

    public function deleteAny(User $staff): bool
    {
        return false;
    }
}
