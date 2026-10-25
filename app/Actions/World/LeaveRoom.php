<?php

namespace App\Actions\World;

use App\Domain\Presence\InstanceMembers;
use App\Domain\World\PositionStore;
use App\Events\World\PlayerLeft;
use App\Models\RoomInstance;
use App\Models\User;

final readonly class LeaveRoom
{
    public function __construct(
        private InstanceMembers $members,
        private PositionStore $positions,
    ) {}

    public function __invoke(User $user, RoomInstance $instance): void
    {
        $wasMember = $this->members->contains($instance->id, $user->id);
        $this->members->remove($instance->id, $user->id);
        $this->positions->forget($user->id);

        if ($user->last_room_instance_id === $instance->id) {
            $user->forceFill(['last_room_instance_id' => null])->save();
            $user->setRelation('lastRoomInstance', null);
        }

        if ($this->members->count($instance->id) === 0) {
            $instance->forceFill(['empty_since' => $instance->empty_since ?? now()])->save();
        }

        // Redis and Postgres are already updated; a websocket outage must not undo a leave.
        if ($wasMember) {
            rescue(fn () => broadcast(new PlayerLeft($instance->id, $user->id)));
        }
    }
}
