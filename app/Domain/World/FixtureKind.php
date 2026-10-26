<?php

namespace App\Domain\World;

/**
 * What a fixture on a map is for. Each kind is one entry here; FixtureReach
 * enforces it, and its refusals read lang/en/hushfield.php "{kind}_elsewhere"
 * (no such fixture in the player's room) and "{kind}_too_far" (not near one).
 * The client side is FIXTURES in resources/js/game/fixtures.ts.
 */
enum FixtureKind: string
{
    /** A shop counter: the shop only trades with players in a room that has one. */
    case Shop = 'shop';

    /** A fishing spot: players can only cast while standing on or next to one. */
    case Fishing = 'fishing';

    /**
     * How many steps from the fixture a player may use it from, diagonals
     * counting as one; null for anywhere in its room.
     */
    public function reach(): ?int
    {
        return match ($this) {
            self::Shop => null,
            self::Fishing => 1,
        };
    }
}
