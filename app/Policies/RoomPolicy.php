<?php

namespace App\Policies;

use App\Models\Room;
use App\Models\User;

/**
 * Staff watch live rooms and move players between instances.
 */
final class RoomPolicy
{
    public function viewAny(User $staff): bool
    {
        return $staff->isStaff();
    }

    public function manageInstances(User $staff, ?Room $room = null): bool
    {
        return $staff->isStaff();
    }
}
