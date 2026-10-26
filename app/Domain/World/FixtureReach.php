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
     * @return RoomInstance The instance the player is using the fixture in.
     *
     * @throws WorldActionRefused when the player's room has no such fixture, or they are not near enough to one.
     */
    public function ensureWithin(User $user, FixtureKind $kind): RoomInstance
    {
        $instance = $user->lastRoomInstance;

        if ($instance === null || ! $this->members->contains($instance->id, $user->id)) {
            throw WorldActionRefused::fixtureElsewhere($kind);
        }

        $fixtures = $this->grids->forRoom($instance->room)->fixtures($kind);

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
