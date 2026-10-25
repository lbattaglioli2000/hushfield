<?php

namespace App\Actions\World;

use App\Models\Room;
use App\Models\RoomInstance;
use App\Models\User;

/**
 * Moves everyone in a room into instance 1.
 */
final readonly class GatherIntoFirstInstance
{
    public function __construct(private CloseInstance $closeInstance) {}

    public function __invoke(Room $room, User $staff): int
    {
        /** @var RoomInstance $first */
        $first = $room->instances()->firstOrCreate(['number' => 1], ['is_open' => true]);
        $first->forceFill(['is_open' => true])->save();

        return $room->instances()
            ->where('number', '>', 1)
            ->where('is_open', true)
            ->get()
            ->sum(fn (RoomInstance $instance): int => ($this->closeInstance)($instance, $staff, $first));
    }
}
