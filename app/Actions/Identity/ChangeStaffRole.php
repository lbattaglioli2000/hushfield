<?php

namespace App\Actions\Identity;

use App\Domain\Audit\AuditLog;
use App\Domain\Identity\StaffRole;
use App\Models\User;

/**
 * Promotes a player to staff, changes a staff role, or demotes staff to player (null).
 * The causer is the staff member who asked, or null for the console, which the
 * audit log shows as System.
 */
final readonly class ChangeStaffRole
{
    /**
     * @return bool Whether the role changed.
     */
    public function __invoke(User $target, ?StaffRole $role, ?User $staff, string $reason): bool
    {
        $from = $target->role;

        if ($from === $role) {
            return false;
        }

        $target->forceFill(['role' => $role])->save();

        $activity = activity(AuditLog::Staff)->performedOn($target);

        // Explicitly anonymous, so a console change never borrows whoever happens to be signed in.
        ($staff === null ? $activity->causedByAnonymous() : $activity->causedBy($staff))
            ->withProperties(['from' => $from?->value, 'to' => $role?->value, 'reason' => $reason])
            ->event('staff.role_changed')
            ->log('Changed staff role');

        return true;
    }
}
