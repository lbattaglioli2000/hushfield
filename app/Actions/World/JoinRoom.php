<?php

namespace App\Actions\World;

use App\Domain\Housing\HomeFloor;
use App\Domain\Housing\Residences;
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
use App\Features\WorldFlag;
use App\Models\Room;
use App\Models\RoomInstance;
use App\Models\User;

/**
 * Puts the player in a room instance and announces them. Public rooms pick an
 * instance (see InstanceAllocator); the residence room puts them in a home (see
 * Residences). They appear at the named arrival point when they came through a
 * door, otherwise at a random spawn.
 */
final readonly class JoinRoom
{
    public function __construct(
        private InstanceAllocator $allocator,
        private PositionStore $positions,
        private WalkabilityGridRepository $grids,
        private HomeFloor $floors,
        private LeaveRoom $leaveRoom,
        private Residences $residences,
    ) {}

    public function __invoke(User $user, Room $room, ?RoomInstance $preferred = null, ?string $arrival = null): JoinResult
    {
        if ($user->isBanned()) {
            throw WorldActionRefused::banned();
        }

        if (! $room->is_active) {
            throw WorldActionRefused::joinsPaused();
        }

        WorldFlag::Joins->ensureActive();

        // Fails before anyone is moved when the room's map is missing.
        $this->grids->forRoom($room);

        // Admitted to the new instance first, so a refused join leaves the player where they were.
        if ($room->isResidence()) {
            $instance = $this->residences->instanceFor($user, $room, $preferred);
            $this->allocator->enter($instance, $user->id);
            $overflowed = false;
        } else {
            [$instance, $overflowed] = $this->allocator->allocate($room, $user->id, $preferred);
        }

        $previous = $user->lastRoomInstance;

        if ($previous !== null && $previous->id !== $instance->id) {
            ($this->leaveRoom)($user, $previous);
        }

        $instance->forceFill(['empty_since' => null])->save();
        $instance->setRelation('room', $room);

        // In a home, never under the furniture.
        $entry = $this->floors->grid($instance)->entryPoint($arrival);
        $position = StoredPath::standingAt($entry[0], $entry[1], Clock::nowMs());
        $this->positions->put($user->id, $position);

        $user->forceFill(['last_room_instance_id' => $instance->id])->save();
        $user->setRelation('lastRoomInstance', $instance);
        $user->loadMissing('avatar');

        WorldBroadcast::toOthers(new PlayerJoined($instance->id, new PlayerState($user, $position)));

        return new JoinResult($instance, $position, $overflowed);
    }
}
