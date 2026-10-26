<?php

namespace App\Actions\Housing;

use App\Domain\Housing\HomeFloor;
use App\Domain\Housing\Residences;
use App\Domain\Items\ItemSlot;
use App\Domain\Presence\InstanceMembers;
use App\Domain\World\Clock;
use App\Domain\World\Footprint;
use App\Domain\World\PositionStore;
use App\Domain\World\WalkabilityGrid;
use App\Domain\World\WalkabilityGridRepository;
use App\Events\World\FurnitureChanged;
use App\Events\WorldBroadcast;
use App\Exceptions\WorldActionRefused;
use App\Models\Home;
use App\Models\HomeFurniture;
use App\Models\Item;
use App\Models\RoomInstance;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Puts a furniture item the resident owns in the room of their home they are
 * standing in, the house or the yard, or moves or turns it there if it is
 * already placed. Every tile it covers must be open floor, clear of
 * doors and arrival points, and clear of other pieces, except that a rug may
 * carry one piece. Pieces that block walking may not cut any of the floor off
 * from the door, nor box in anyone standing where they land.
 */
final readonly class PlaceFurniture
{
    public function __construct(
        private Residences $residences,
        private WalkabilityGridRepository $grids,
        private InstanceMembers $members,
        private PositionStore $positions,
    ) {}

    /**
     * @param  int|null  $rotation  Quarter turns, 0–3. Null keeps a placed piece's turn, or 0 for a new one.
     */
    public function __invoke(User $owner, Item $item, int $x, int $y, ?int $rotation = null): RoomInstance
    {
        $owned = $owner->inventory()->where('item_id', $item->id)->first();

        if ($owned === null || $item->slot !== ItemSlot::Furniture) {
            throw WorldActionRefused::notFurniture();
        }

        $residence = $this->residences->room() ?? throw WorldActionRefused::residencesClosed();
        $home = $this->residences->homeOf($owner, $residence);
        $instance = $home->partWith($owner);
        $grid = $this->grids->forRoom($instance->room);

        // One arrangement change at a time per home, so two requests can't both pass the checks.
        $from = DB::transaction(function () use ($home, $instance, $owned, $item, $x, $y, $rotation, $grid): ?int {
            Home::query()->whereKey($home->id)->lockForUpdate()->first();

            /** @var Collection<int, HomeFurniture> $placed */
            $placed = $home->furniture()->with('inventoryItem.item')->where('inventory_item_id', '!=', $owned->id)->get();
            $current = HomeFurniture::query()->where('home_id', $home->id)->where('inventory_item_id', $owned->id)->first();

            // House and yard share one allowance.
            if ($current === null && $placed->count() >= (int) config('hushfield.housing.max_furniture')) {
                throw WorldActionRefused::furnitureFull();
            }

            $others = $placed->where('room_instance_id', $instance->id);
            $rotation ??= $current->rotation ?? 0;
            $footprint = $item->footprintAt($x, $y, $rotation);

            $this->ensureOnFloor($footprint, $grid);
            $this->ensureClear($footprint, $item, $others);

            if (! $item->floor) {
                $this->ensureNothingCutOff($footprint, $instance, $others, $grid);
            }

            HomeFurniture::query()->updateOrCreate(
                ['inventory_item_id' => $owned->id],
                ['home_id' => $home->id, 'room_instance_id' => $instance->id, 'x' => $x, 'y' => $y, 'rotation' => $rotation],
            );

            return $current?->room_instance_id;
        });

        WorldBroadcast::send(new FurnitureChanged($instance->id, $instance->furniturePayload()));

        // Carried between house and yard: the room it left changes too.
        if ($from !== null && $from !== $instance->id) {
            $left = RoomInstance::query()->findOrFail($from);
            WorldBroadcast::send(new FurnitureChanged($left->id, $left->furniturePayload()));
        }

        return $instance;
    }

    private function ensureOnFloor(Footprint $footprint, WalkabilityGrid $grid): void
    {
        foreach ($footprint->tiles() as [$x, $y]) {
            if (! $grid->isWalkable($x, $y)) {
                throw WorldActionRefused::furnitureOffFloor();
            }

            if ($grid->isExit($x, $y) || in_array([$x, $y], $grid->arrivals, true)) {
                throw WorldActionRefused::furnitureInDoorway();
            }
        }
    }

    /**
     * Pieces may only overlap one on top of a rug, and each rug carries one piece.
     *
     * @param  Collection<int, HomeFurniture>  $others
     */
    private function ensureClear(Footprint $footprint, Item $item, Collection $others): void
    {
        $under = $others->filter(fn (HomeFurniture $other): bool => $other->footprint()->overlaps($footprint));

        foreach ($under as $other) {
            $rug = $item->floor ? $footprint : $other->footprint();
            $carried = $others->filter(fn (HomeFurniture $piece): bool => $piece !== $other && $piece->blocks() && $piece->footprint()->overlaps($rug));

            if ($item->floor === $other->inventoryItem->item->floor || $carried->isNotEmpty()) {
                throw WorldActionRefused::furnitureBlocked();
            }
        }
    }

    /**
     * @param  Collection<int, HomeFurniture>  $others
     */
    private function ensureNothingCutOff(Footprint $footprint, RoomInstance $instance, Collection $others, WalkabilityGrid $grid): void
    {
        $floor = HomeFloor::without($grid, $others)->withBlocked($footprint->tiles());

        if (! HomeFloor::allReachableFromDoor($floor)) {
            throw WorldActionRefused::furnitureCutsOff();
        }

        $now = Clock::nowMs();

        foreach ($this->positions->many($this->members->all($instance->id)) as $path) {
            $position = $path->positionAt($now);

            // Where they stand now, and where they will stop.
            foreach ([$position->tile, $path->destination()] as [$x, $y]) {
                if ($footprint->covers($x, $y) && ! HomeFloor::canStepOff($floor, $x, $y)) {
                    throw WorldActionRefused::furnitureTraps();
                }
            }
        }
    }
}
