<?php

namespace App\Actions\World;

use App\Domain\Presence\InstanceMembers;
use App\Domain\World\Clock;
use App\Domain\World\JoinResult;
use App\Domain\World\PositionStore;
use App\Domain\World\WalkabilityGridRepository;
use App\Exceptions\WorldActionRefused;
use App\Models\Room;
use App\Models\RoomInstance;
use App\Models\User;

/**
 * Takes the player through a door on their map into the room it leads to.
 * They must be heading for one of the door's tiles and within a step of it: the client asks
 * as its own avatar arrives, which can be a moment ahead of the server's clock.
 */
final readonly class UseExit
{
    public function __construct(
        private InstanceMembers $members,
        private PositionStore $positions,
        private WalkabilityGridRepository $grids,
        private JoinRoom $joinRoom,
    ) {}

    public function __invoke(User $user, RoomInstance $instance, string $exitName): JoinResult
    {
        $this->members->ensureContains($instance->id, $user->id);

        $room = $instance->room;
        $exit = $this->grids->forRoom($room)->exits[$exitName] ?? throw WorldActionRefused::exitClosed();
        $path = $this->positions->currentOrSpawn($user->id, $room, Clock::nowMs());
        $position = $path->positionAt(Clock::nowMs());

        [$x, $y] = $path->destination();

        if (! $exit->covers($x, $y) || max(abs($position->x - $x), abs($position->y - $y)) > 1) {
            throw WorldActionRefused::exitTooFar();
        }

        $target = Room::query()->where('slug', $exit->to)->where('is_active', true)->first()
            ?? throw WorldActionRefused::exitClosed();

        return ($this->joinRoom)($user, $target, arrival: $exit->arrive);
    }
}
