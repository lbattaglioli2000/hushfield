<?php

namespace App\Actions\Housing;

use App\Events\World\FurnitureChanged;
use App\Events\WorldBroadcast;
use App\Models\InventoryItem;
use App\Models\RoomInstance;
use App\Models\User;

/**
 * Takes one placed piece off the floor, in the house or the yard; the resident
 * still owns it. Returns the room of the home the resident is standing in.
 */
final readonly class PutAwayFurniture
{
    public function __invoke(User $owner, InventoryItem $owned): ?RoomInstance
    {
        $home = $owner->home;

        if ($home === null) {
            return null;
        }

        $placed = $home->furniture()->where('inventory_item_id', $owned->id)->first();

        if ($placed !== null) {
            $placed->delete();
            $instance = RoomInstance::query()->findOrFail($placed->room_instance_id);
            WorldBroadcast::send(new FurnitureChanged($instance->id, $instance->furniturePayload()));
        }

        return $home->partWith($owner);
    }
}
