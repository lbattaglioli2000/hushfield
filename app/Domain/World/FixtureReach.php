<?php

namespace App\Domain\World;

use App\Domain\Presence\InstanceMembers;
use App\Exceptions\WorldActionRefused;
use App\Models\RoomInstance;
use App\Models\User;

/**
 * Places, not menus: an action tied to a fixture (the shop, fishing) only
 * works for a player in a room whose map has one of that kind, and within
 * the kind's reach of it.
 */
final readonly class FixtureReach
{
    public function __construct(
        private InstanceMembers $members,
        private WalkabilityGridRepository $grids,
        private PositionStore $positions,
    ) {}

    /**
     * @param  string|null  $name  Only the fixture of this name will do, such as one library shelf among several.
     * @return RoomInstance The instance the player is using the fixture in.
     *
     * @throws WorldActionRefused when the player's room has no such fixture, or they are not near enough to one.
     */
    public function ensureWithin(User $user, FixtureKind $kind, ?string $name = null): RoomInstance
    {
        $instance = $user->lastRoomInstance;

        if ($instance === null || ! $this->members->contains($instance->id, $user->id)) {
            throw WorldActionRefused::fixtureElsewhere($kind);
        }

        $fixtures = array_values(array_filter(
            $this->grids->forRoom($instance->room)->fixtures($kind),
            fn (MapFixture $fixture): bool => $name === null || $fixture->name === $name,
        ));

        if ($fixtures === []) {
            throw WorldActionRefused::fixtureElsewhere($kind);
        }

        if ($kind->reach() === null) {
            return $instance;
        }

        $now = Clock::nowMs();
        $position = $this->positions->currentOrSpawn($user->id, $instance->room, $now)->positionAt($now);

        foreach ($fixtures as $fixture) {
            if ($fixture->distanceTo($position->x, $position->y) <= $kind->reach()) {
                return $instance;
            }
        }

        throw WorldActionRefused::fixtureTooFar($kind);
    }
}
