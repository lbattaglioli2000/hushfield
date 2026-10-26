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
 * residence room (the house), created the first time they need it, and an
 * instance of the yard room behind it, created the first time anyone steps out.
 */
final readonly class Residences
{
    public function __construct(private InstanceAllocator $allocator) {}

    /**
     * The residence room, the house every home has, if its map has been imported.
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

            $home = Home::query()->create(['user_id' => $owner->id, 'room_instance_id' => $instance->id, 'visitors' => HomeVisitors::Closed]);
            $instance->forceFill(['home_id' => $home->id])->save();

            return $home;
        });

        $owner->setRelation('home', $home);

        return $home;
    }

    /**
     * Which home a resident entering the house or the yard goes to, and its
     * instance of that room. The preferred instance names the home: one they
     * asked to visit, or the room of a home they are leaving, so the back door
     * leads to that same home's yard and back. They must be admitted to it.
     * Without one, they go to their own.
     */
    public function instanceFor(User $visitor, Room $room, ?RoomInstance $preferred): RoomInstance
    {
        $home = $preferred !== null && $preferred->room->isResidence() ? $preferred->home : null;

        if ($home === null) {
            $home = $this->homeOf($visitor, $this->room() ?? throw WorldActionRefused::residencesClosed());
        } elseif (! $home->admits($visitor)) {
            throw WorldActionRefused::homeClosed();
        }

        // A yard is laid out the first time anyone steps into it, its number allocated under the room's lock.
        $instance = $room->instances()->where('home_id', $home->id)->first()
            ?? $this->allocator->lockFor($room->id)->block(5, fn (): RoomInstance => $room->instances()->firstOrCreate(
                ['home_id' => $home->id],
                ['number' => ((int) $room->instances()->max('number')) + 1, 'is_open' => true],
            ));

        $instance->setRelation('room', $room);
        $instance->setRelation('home', $home);

        return $instance;
    }
}
