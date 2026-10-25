<?php

namespace App\Domain\World;

/**
 * What a fixture on a map does when a player uses it.
 */
enum FixtureKind: string
{
    /** A shop counter: the shop only trades with players in a room that has one. */
    case Shop = 'shop';

    /** A fishing spot: players can only cast while standing on or next to one. */
    case Fishing = 'fishing';
}
