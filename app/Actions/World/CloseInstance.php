<?php

namespace App\Actions\World;

use App\Domain\Presence\InstanceMembers;
use App\Events\Presence\InstanceReassigned;
use App\Models\RoomInstance;
use App\Models\User;

/**
 * Closes an instance and sends everyone in it elsewhere. Their clients rejoin
 * on their own, landing in the busiest open instance (or $target when given).
 */
final readonly class CloseInstance
{
    public function __construct(
        private InstanceMembers $members,
        private LeaveRoom $leaveRoom,
    ) {}

    public function __invoke(RoomInstance $instance, ?User $staff, ?RoomInstance $target = null, bool $keepOpen = false): int
    {
        if (! $keepOpen) {
            $instance->forceFill(['is_open' => false])->save();
        }

        $users = User::query()->with('worldSession')->whereIn('id', $this->members->all($instance->id))->get();

        foreach ($users as $user) {
            ($this->leaveRoom)($user, $instance);

            if ($user->worldSession !== null) {
                broadcast(new InstanceReassigned($user->worldSession->session_token, $target?->id));
            }
        }

        if ($staff !== null) {
            activity('rooms')
                ->performedOn($instance)
                ->causedBy($staff)
                ->withProperties(['moved' => $users->count(), 'target_instance_id' => $target?->id, 'closed' => ! $keepOpen])
                ->event($keepOpen ? 'instance.emptied' : 'instance.closed')
                ->log($keepOpen ? 'Moved everyone out of instance' : 'Closed instance');
        }

        return $users->count();
    }
}
