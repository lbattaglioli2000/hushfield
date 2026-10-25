<?php

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\DeniesPanelWrites;
use Spatie\Activitylog\Models\Activity;

/**
 * The audit log is read-only for everyone. Staff can read it.
 */
final class ActivityPolicy
{
    use DeniesPanelWrites;

    public function viewAny(User $staff): bool
    {
        return $staff->isStaff();
    }

    public function view(User $staff, Activity $activity): bool
    {
        return $staff->isStaff();
    }
}
