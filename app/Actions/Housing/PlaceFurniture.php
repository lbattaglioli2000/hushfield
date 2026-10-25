<?php

namespace App\Actions\Housing;

use App\Domain\Housing\Residences;
use App\Domain\Items\ItemSlot;
use App\Domain\World\WalkabilityGridRepository;
use App\Events\World\FurnitureChanged;
use App\Events\WorldBroadcast;
use App\Exceptions\WorldActionRefused;
use App\Models\Home;
use App\Models\HomeFurniture;
use App\Models\Item;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Puts a furniture item the resident owns on a tile of their home, or moves
 * it there if it is already placed. One piece per tile; never on a door.
 */
final readonly class PlaceFurniture
{
    public function __construct(
        private Residences $residences,
        private WalkabilityGridRepository $grids,
    ) {}

    public function __invoke(User $owner, Item $item, int $x, int $y): Home
    {
        $owned = $owner->inventory()->where('item_id', $item->id)->first();

        if ($owned === null || $item->slot !== ItemSlot::Furniture) {
            throw WorldActionRefused::notFurniture();
        }

        $residence = $this->residences->room() ?? throw WorldActionRefused::residencesClosed();
        $grid = $this->grids->forRoom($residence);

        if (! $grid->isWalkable($x, $y) || $grid->isExit($x, $y) || in_array([$x, $y], $grid->arrivals, true)) {
            throw WorldActionRefused::furnitureBlocked();
        }

        $home = $this->residences->homeOf($owner, $residence);

        $taken = $home->furniture()->where('x', $x)->where('y', $y)->where('inventory_item_id', '!=', $owned->id)->exists();

        if ($taken) {
            throw WorldActionRefused::furnitureBlocked();
        }

        try {
            HomeFurniture::query()->updateOrCreate(
                ['inventory_item_id' => $owned->id],
                ['home_id' => $home->id, 'x' => $x, 'y' => $y],
            );
        } catch (UniqueConstraintViolationException) {
            // Someone else's request took the tile between the check and the write.
            throw WorldActionRefused::furnitureBlocked();
        }

        WorldBroadcast::send(new FurnitureChanged($home->room_instance_id, $home->furniturePayload()));

        return $home;
    }
}
