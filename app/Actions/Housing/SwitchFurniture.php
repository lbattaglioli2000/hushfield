<?php

namespace App\Actions\Housing;

use App\Domain\Assets\ItemArt;
use App\Domain\Presence\InstanceMembers;
use App\Events\World\FurnitureChanged;
use App\Events\WorldBroadcast;
use App\Exceptions\WorldActionRefused;
use App\Models\HomeFurniture;
use App\Models\InventoryItem;
use App\Models\RoomInstance;
use App\Models\User;

/**
 * Switches a placed piece of furniture on or off, such as a washer, which
 * tumbles while it's on. Anyone in the room it stands in may, the owner or
 * a visitor, as long as one of the facings its art draws has an animation
 * to play. It asks for a state rather than flipping it, so two people
 * tapping at once agree. Everyone inside sees it.
 */
final readonly class SwitchFurniture
{
    public function __construct(
        private InstanceMembers $members,
        private ItemArt $art,
    ) {}

    public function __invoke(User $user, InventoryItem $owned, bool $on): RoomInstance
    {
        $placed = HomeFurniture::query()->with('inventoryItem.item')->where('inventory_item_id', $owned->id)->first();

        if ($placed === null || ! $this->members->contains($placed->room_instance_id, $user->id)) {
            throw WorldActionRefused::furnitureNotHere();
        }

        if (! $this->art->switches($placed->inventoryItem->item)) {
            throw WorldActionRefused::furnitureDoesNotSwitch();
        }

        $instance = RoomInstance::query()->findOrFail($placed->room_instance_id);

        if ($placed->switched_on !== $on) {
            $placed->forceFill(['switched_on' => $on])->save();
            WorldBroadcast::send(new FurnitureChanged($instance->id, $instance->furniturePayload()));
        }

        return $instance;
    }
}
