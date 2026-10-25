<?php

namespace App\Actions\Housing;

use App\Events\World\FurnitureChanged;
use App\Events\WorldBroadcast;
use App\Models\Home;
use App\Models\Item;
use App\Models\User;

/**
 * Takes a placed piece off the floor; the resident still owns it.
 */
final readonly class PutAwayFurniture
{
    public function __invoke(User $owner, Item $item): ?Home
    {
        $home = $owner->home;

        if ($home === null) {
            return null;
        }

        $home->furniture()->whereRelation('inventoryItem', 'item_id', $item->id)->delete();

        WorldBroadcast::send(new FurnitureChanged($home->room_instance_id, $home->furniturePayload()));

        return $home;
    }
}
