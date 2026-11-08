<?php

namespace App\Actions\Avatars;

use App\Events\World\OutfitChanged;
use App\Events\WorldBroadcast;
use App\Exceptions\WorldActionRefused;
use App\Models\Avatar;
use App\Models\InventoryItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Puts on an item the resident owns, in its slot, taking off whatever was
 * there, or takes it off. Everyone in their room sees the new look.
 */
final readonly class ChangeOutfit
{
    public function __invoke(User $user, InventoryItem $owned, bool $wear): void
    {
        $slot = $owned->item->slot;

        if ($owned->user_id !== $user->id || ! $slot->isWorn()) {
            throw WorldActionRefused::notWearable();
        }

        /** @var Avatar $avatar */
        $avatar = $user->avatar()->firstOrFail();

        DB::transaction(function () use ($avatar, $owned, $slot, $wear): void {
            $avatar->worn()->wherePivot('slot', $slot->value)->detach();
            $avatar->worn()->detach($owned->id);

            if ($wear) {
                $avatar->worn()->attach($owned->id, ['slot' => $slot->value]);
            }
        });

        if ($user->last_room_instance_id !== null) {
            WorldBroadcast::send(new OutfitChanged($user->last_room_instance_id, $user->id, $avatar->load('worn.item')->toPayload()));
        }
    }
}
