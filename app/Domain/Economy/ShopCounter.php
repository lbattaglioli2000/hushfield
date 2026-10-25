<?php

namespace App\Domain\Economy;

use App\Domain\Presence\InstanceMembers;
use App\Domain\World\FixtureKind;
use App\Domain\World\WalkabilityGridRepository;
use App\Exceptions\WorldActionRefused;
use App\Models\User;

/**
 * The shop is a place, not a menu: it only trades with a player standing in
 * a room whose map has a shop counter (see the "fixtures" map layer).
 */
final readonly class ShopCounter
{
    public function __construct(
        private InstanceMembers $members,
        private WalkabilityGridRepository $grids,
    ) {}

    /**
     * @throws WorldActionRefused when the player is not in a room with a shop counter.
     */
    public function ensureWithinReach(User $user): void
    {
        $instance = $user->lastRoomInstance;

        if ($instance === null
            || ! $this->members->contains($instance->id, $user->id)
            || ! $this->grids->forRoom($instance->room)->hasFixture(FixtureKind::Shop)) {
            throw WorldActionRefused::shopElsewhere();
        }
    }
}
