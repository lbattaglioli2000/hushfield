<?php

namespace App\Actions\World;

use App\Domain\World\Clock;
use App\Domain\World\InstanceAllocator;
use App\Domain\World\JoinResult;
use App\Domain\World\PlayerState;
use App\Domain\World\PositionStore;
use App\Domain\World\StoredPath;
use App\Domain\World\WalkabilityGridRepository;
use App\Events\World\PlayerJoined;
use App\Events\WorldBroadcast;
use App\Exceptions\WorldActionRefused;
use App\Features\JoinsEnabled;
use App\Features\WorldFlags;
use App\Models\Room;
use App\Models\RoomInstance;
use App\Models\User;

/**
 * Puts the player in a room instance (see InstanceAllocator) at a random spawn and announces them.
 */
final readonly class JoinRoom
{
    public function __construct(
        private InstanceAllocator $allocator,
        private PositionStore $positions,
        private WalkabilityGridRepository $grids,
        private LeaveRoom $leaveRoom,
    ) {}

    public function __invoke(User $user, Room $room, ?RoomInstance $preferred = null): JoinResult
    {
        if ($user->isBanned()) {
            throw WorldActionRefused::banned();
        }

        if (! $room->is_active || ! WorldFlags::active(JoinsEnabled::class)) {
            throw WorldActionRefused::joinsPaused();
        }

        $grid = $this->grids->forRoom($room);

        if ($user->lastRoomInstance !== null) {
            ($this->leaveRoom)($user, $user->lastRoomInstance);
        }

        [$instance, $overflowed] = $this->allocator->allocate($room, $user->id, $preferred);

        $instance->forceFill(['empty_since' => null])->save();
        $instance->setRelation('room', $room);

        $spawn = $grid->spawns[array_rand($grid->spawns)];
        $position = StoredPath::standingAt($spawn[0], $spawn[1], Clock::nowMs());
        $this->positions->put($user->id, $position);

        $user->forceFill(['last_room_instance_id' => $instance->id])->save();
        $user->setRelation('lastRoomInstance', $instance);
        $user->loadMissing('avatar');

        WorldBroadcast::toOthers(new PlayerJoined($instance->id, new PlayerState($user, $position)));

        return new JoinResult($instance, $position, $overflowed);
    }
}
