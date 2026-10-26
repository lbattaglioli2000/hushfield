<?php

namespace App\Actions\World;

use App\Domain\Fishing\CastStore;
use App\Domain\Presence\InstanceMembers;
use App\Domain\Voice\LiveKit;
use App\Domain\World\PositionStore;
use App\Events\World\PlayerLeft;
use App\Events\WorldBroadcast;
use App\Models\RoomInstance;
use App\Models\User;

use function Illuminate\Support\defer;

final readonly class LeaveRoom
{
    public function __construct(
        private InstanceMembers $members,
        private PositionStore $positions,
        private CastStore $casts,
        private LiveKit $liveKit,
    ) {}

    public function __invoke(User $user, RoomInstance $instance): void
    {
        $wasMember = $this->members->contains($instance->id, $user->id);
        $this->members->remove($instance->id, $user->id);
        $this->positions->forget($user->id);
        // A line left in the water when its fisher walks off is simply gone.
        $this->casts->forget($user->id);

        // Voice is per instance: whoever leaves stops hearing and being heard there. Minors never join it.
        if (! $user->is_minor && $this->liveKit->configured()) {
            defer(fn () => $this->liveKit->removeParticipant($instance->id, $user->id));
        }

        if ($user->last_room_instance_id === $instance->id) {
            $user->forceFill(['last_room_instance_id' => null])->save();
            $user->setRelation('lastRoomInstance', null);
        }

        if ($this->members->count($instance->id) === 0) {
            $instance->forceFill(['empty_since' => $instance->empty_since ?? now()])->save();
        }

        if ($wasMember) {
            WorldBroadcast::send(new PlayerLeft($instance->id, $user->id));
        }
    }
}
