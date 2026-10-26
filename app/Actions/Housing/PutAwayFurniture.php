<?php

namespace App\Actions\Housing;

use App\Events\World\FurnitureChanged;
use App\Events\WorldBroadcast;
use App\Models\HomeFurniture;
use App\Models\Item;
use App\Models\RoomInstance;
use App\Models\User;

/**
 * Takes a placed piece off the floor, in the house or the yard; the resident
 * still owns it. Returns the room of the home the resident is standing in.
 */
final readonly class PutAwayFurniture
{
    public function __invoke(User $owner, Item $item): ?RoomInstance
    {
        $home = $owner->home;

        if ($home === null) {
            return null;
        }

        $pieces = $home->furniture()->whereRelation('inventoryItem', 'item_id', $item->id)->get();
        HomeFurniture::query()->whereKey($pieces->modelKeys())->delete();

        foreach (RoomInstance::query()->findMany($pieces->pluck('room_instance_id')->unique()) as $instance) {
            WorldBroadcast::send(new FurnitureChanged($instance->id, $instance->furniturePayload()));
        }

        return $home->partWith($owner);
    }
}
