<?php

namespace App\Policies;

use App\Models\User;
use Spatie\Activitylog\Models\Activity;

/**
 * The audit log is read-only for everyone. Staff can read it.
 */
final class ActivityPolicy
{
    public function viewAny(User $staff): bool
    {
        return $staff->isStaff();
    }

    public function view(User $staff, Activity $activity): bool
    {
        return $staff->isStaff();
    }

    public function create(User $staff): bool
    {
        return false;
    }

    public function update(User $staff, Activity $activity): bool
    {
        return false;
    }

    public function delete(User $staff, Activity $activity): bool
    {
        return false;
    }

    public function deleteAny(User $staff): bool
    {
        return false;
    }
}
