<?php

namespace App\Actions\World;

use App\Domain\Presence\InstanceMembers;
use App\Domain\World\Clock;
use App\Domain\World\JoinResult;
use App\Domain\World\PositionStore;
use App\Domain\World\StoredPath;
use App\Domain\World\WalkabilityGridRepository;
use App\Events\World\PlayerJoined;
use App\Exceptions\WorldActionRefused;
use App\Features\JoinsEnabled;
use App\Features\WorldFlags;
use App\Models\Room;
use App\Models\RoomInstance;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Puts the player in the busiest open instance that still has room, opening another when all are full.
 */
final readonly class JoinRoom
{
    public function __construct(
        private InstanceMembers $members,
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

        [$instance, $overflowed] = Cache::lock("join-room:{$room->id}", 5)->block(5, function () use ($room, $user, $preferred): array {
            [$instance, $overflowed] = $this->pickInstance($room, $preferred);
            $this->members->add($instance->id, $user->id);

            return [$instance, $overflowed];
        });

        $instance->forceFill(['empty_since' => null])->save();
        $instance->setRelation('room', $room);

        $spawn = $grid->spawns[array_rand($grid->spawns)];
        $position = StoredPath::standingAt($spawn[0], $spawn[1], Clock::nowMs());
        $this->positions->put($user->id, $position);

        $user->forceFill(['last_room_instance_id' => $instance->id])->save();
        $user->setRelation('lastRoomInstance', $instance);
        $user->loadMissing('avatar');

        broadcast(new PlayerJoined($instance, $user, $position))->toOthers();

        return new JoinResult($instance, $position, $overflowed);
    }

    /**
     * @return array{RoomInstance, bool}
     */
    private function pickInstance(Room $room, ?RoomInstance $preferred): array
    {
        $capacity = $room->capacity_per_instance;

        if ($preferred !== null && $preferred->room_id === $room->id && $preferred->is_open && $this->members->count($preferred->id) < $capacity) {
            return [$preferred, false];
        }

        $open = $room->instances()->where('is_open', true)->orderBy('number')->get();
        $counts = $this->members->counts($open->modelKeys());

        $best = $open
            ->filter(fn (RoomInstance $instance): bool => $counts[$instance->id] < $capacity)
            ->sortBy([fn (RoomInstance $a, RoomInstance $b): int => $counts[$b->id] <=> $counts[$a->id] ?: $a->number <=> $b->number])
            ->first();

        if ($best !== null) {
            return [$best, false];
        }

        $closed = $room->instances()->where('is_open', false)->orderBy('number')->first();

        if ($closed !== null) {
            $closed->forceFill(['is_open' => true])->save();

            return [$closed, $open->isNotEmpty()];
        }

        /** @var RoomInstance $created */
        $created = $room->instances()->create([
            'number' => ((int) $room->instances()->max('number')) + 1,
            'is_open' => true,
        ]);

        return [$created, $open->isNotEmpty()];
    }
}
