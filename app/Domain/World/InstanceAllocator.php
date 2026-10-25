<?php

namespace App\Domain\World;

use App\Domain\Presence\InstanceMembers;
use App\Models\Room;
use App\Models\RoomInstance;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

/**
 * Decides which instance of a room a joining player lands in, and adds them to it.
 *
 * Everything that opens, closes, or fills a room's instances holds the room's
 * lock, so a join never lands in an instance that is being closed.
 */
final readonly class InstanceAllocator
{
    public function __construct(private InstanceMembers $members) {}

    public function lockFor(int $roomId): Lock
    {
        return Cache::lock("join-room:{$roomId}", 5);
    }

    /**
     * Puts the player in the preferred instance when it is open and has room,
     * otherwise in the busiest open instance that still has room, otherwise in
     * a reopened or new instance.
     *
     * @return array{RoomInstance, bool} The instance, and whether the player overflowed out of the full ones.
     */
    public function allocate(Room $room, int $userId, ?RoomInstance $preferred = null): array
    {
        return $this->lockFor($room->id)->block(5, function () use ($room, $userId, $preferred): array {
            $allocation = $this->pick($room, $preferred);
            $this->members->add($allocation[0]->id, $userId);

            return $allocation;
        });
    }

    /**
     * @return array{RoomInstance, bool}
     */
    private function pick(Room $room, ?RoomInstance $preferred): array
    {
        $capacity = $room->capacity_per_instance;

        // Read is_open under the lock: the caller's copy may predate a close.
        $preferred = $preferred?->room_id === $room->id
            ? $room->instances()->whereKey($preferred->id)->where('is_open', true)->first()
            : null;

        if ($preferred !== null && $this->members->count($preferred->id) < $capacity) {
            return [$preferred, false];
        }

        $open = $room->instances()->where('is_open', true)->orderBy('number')->get();
        $counts = $this->members->countsFor($open);

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
