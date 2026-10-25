<?php

namespace App\Actions\Identity;

use App\Domain\Audit\AuditLog;
use App\Domain\Identity\StaffRole;
use App\Models\User;

/**
 * Promotes a player to staff, changes a staff role, or demotes staff to player (null).
 */
final readonly class ChangeStaffRole
{
    /**
     * @return bool Whether the role changed.
     */
    public function __invoke(User $target, ?StaffRole $role, User $staff, string $reason): bool
    {
        $from = $target->role;

        if ($from === $role) {
            return false;
        }

        $target->forceFill(['role' => $role])->save();

        activity(AuditLog::Staff)
            ->performedOn($target)
            ->causedBy($staff)
            ->withProperties(['from' => $from?->value, 'to' => $role?->value, 'reason' => $reason])
            ->event('staff.role_changed')
            ->log('Changed staff role');

        return true;
    }
}
