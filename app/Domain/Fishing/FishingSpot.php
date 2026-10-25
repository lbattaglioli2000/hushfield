<?php

namespace App\Domain\Fishing;

use App\Domain\Presence\InstanceMembers;
use App\Domain\World\Clock;
use App\Domain\World\FixtureKind;
use App\Domain\World\MapFixture;
use App\Domain\World\PositionStore;
use App\Domain\World\WalkabilityGridRepository;
use App\Exceptions\WorldActionRefused;
use App\Models\RoomInstance;
use App\Models\User;

/**
 * Fishing is a place too: a line only goes in from a room whose map has a
 * fishing spot (see the "fixtures" map layer), with the player on or beside one.
 */
final readonly class FishingSpot
{
    public function __construct(
        private InstanceMembers $members,
        private WalkabilityGridRepository $grids,
        private PositionStore $positions,
    ) {}

    /**
     * @return RoomInstance The instance the player is fishing in.
     *
     * @throws WorldActionRefused when the room has no fishing spot, or the player is not at one.
     */
    public function ensureWithinReach(User $user): RoomInstance
    {
        $instance = $user->lastRoomInstance;

        if ($instance === null || ! $this->members->contains($instance->id, $user->id)) {
            throw WorldActionRefused::fishingElsewhere();
        }

        $spots = array_filter(
            $this->grids->forRoom($instance->room)->fixtures,
            fn (MapFixture $fixture): bool => $fixture->kind === FixtureKind::Fishing,
        );

        if ($spots === []) {
            throw WorldActionRefused::fishingElsewhere();
        }

        $now = Clock::nowMs();
        $position = $this->positions->currentOrSpawn($user->id, $instance->room, $now)->positionAt($now);

        foreach ($spots as $spot) {
            if (max(abs($position->x - $spot->x), abs($position->y - $spot->y)) <= 1) {
                return $instance;
            }
        }

        throw WorldActionRefused::fishingTooFar();
    }
}
