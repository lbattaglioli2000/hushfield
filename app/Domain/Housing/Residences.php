<?php

namespace App\Domain\Housing;

use App\Domain\World\InstanceAllocator;
use App\Domain\World\RoomKind;
use App\Exceptions\WorldActionRefused;
use App\Models\Home;
use App\Models\Room;
use App\Models\RoomInstance;
use App\Models\User;

/**
 * Hearthmore Residences: every resident's home is an instance of the one
 * residence room, created the first time they need it.
 */
final readonly class Residences
{
    public function __construct(private InstanceAllocator $allocator) {}

    /**
     * The residence room, if its map has been imported.
     */
    public function room(): ?Room
    {
        return Room::query()->where('kind', RoomKind::Residence)->where('is_active', true)->orderBy('id')->first();
    }

    public function homeOf(User $owner, Room $residence): Home
    {
        $home = $owner->home;

        if ($home !== null) {
            return $home;
        }

        // Instance numbers are allocated under the room's lock, like any other new instance.
        $home = $this->allocator->lockFor($residence->id)->block(5, function () use ($owner, $residence): Home {
            $existing = Home::query()->where('user_id', $owner->id)->first();

            if ($existing !== null) {
                return $existing;
            }

            /** @var RoomInstance $instance */
            $instance = $residence->instances()->create([
                'number' => ((int) $residence->instances()->max('number')) + 1,
                'is_open' => true,
            ]);

            return Home::query()->create(['user_id' => $owner->id, 'room_instance_id' => $instance->id, 'visitors' => HomeVisitors::Closed]);
        });

        $owner->setRelation('home', $home);

        return $home;
    }

    /**
     * Which home a resident entering the residence room goes to: the preferred
     * one when they may enter it, their own when none is given.
     */
    public function instanceFor(User $visitor, Room $residence, ?RoomInstance $preferred): RoomInstance
    {
        $home = $preferred?->room_id === $residence->id ? $preferred->home : null;

        if ($home === null) {
            $home = $this->homeOf($visitor, $residence);
        } elseif (! $home->admits($visitor)) {
            throw WorldActionRefused::homeClosed();
        }

        $instance = $home->instance;
        $instance->setRelation('room', $residence);
        $instance->setRelation('home', $home);

        return $instance;
    }
}
