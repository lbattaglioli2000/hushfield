<?php

namespace App\Events\World;

use App\Events\RoomEvent;

/**
 * A home's furniture was placed, moved, switched or put away. Carries only
 * the room: clients fetch the whole arrangement (FurnitureController), which
 * outgrows a websocket message (10 KB) at around ten pieces.
 */
final class FurnitureChanged extends RoomEvent
{
    protected function payload(): array
    {
        return [];
    }
}
