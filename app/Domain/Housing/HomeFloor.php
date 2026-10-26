<?php

namespace App\Domain\Housing;

use App\Domain\World\WalkabilityGrid;
use App\Domain\World\WalkabilityGridRepository;
use App\Models\HomeFurniture;
use App\Models\RoomInstance;

/**
 * Where players may walk in an instance. In a public room that is the map; in
 * a home it is the map minus the tiles under furniture, rugs excepted.
 */
final readonly class HomeFloor
{
    public function __construct(private WalkabilityGridRepository $grids) {}

    public function grid(RoomInstance $instance): WalkabilityGrid
    {
        $grid = $this->grids->forRoom($instance->room);

        if (! $instance->room->isResidence() || $instance->home === null) {
            return $grid;
        }

        return self::without($grid, $instance->furniture()->with('inventoryItem.item')->get()->all());
    }

    /**
     * @param  iterable<HomeFurniture>  $furniture
     */
    public static function without(WalkabilityGrid $grid, iterable $furniture): WalkabilityGrid
    {
        $tiles = [];

        foreach ($furniture as $piece) {
            if ($piece->blocks()) {
                array_push($tiles, ...$piece->footprint()->tiles());
            }
        }

        return $grid->withBlocked($tiles);
    }

    /**
     * Whether every walkable tile, every other door's included, can still be
     * reached from a door. Starting from one door keeps a house's front and back
     * doors joined. Diagonal steps never cut corners, so they reach nothing that
     * straight steps can't.
     */
    public static function allReachableFromDoor(WalkabilityGrid $grid): bool
    {
        $door = array_values($grid->exits)[0] ?? null;

        if ($door === null) {
            return true;
        }

        $queue = [[$door->x, $door->y]];
        $seen = [];

        foreach ($queue as [$x, $y]) {
            $seen[$y * $grid->width + $x] = true;
        }

        while ($queue !== []) {
            [$x, $y] = array_pop($queue);

            foreach ([[0, -1], [1, 0], [0, 1], [-1, 0]] as [$dx, $dy]) {
                $key = ($y + $dy) * $grid->width + $x + $dx;

                if (! isset($seen[$key]) && $grid->isWalkable($x + $dx, $y + $dy)) {
                    $seen[$key] = true;
                    $queue[] = [$x + $dx, $y + $dy];
                }
            }
        }

        return substr_count($grid->cells, '1') <= count($seen);
    }

    /**
     * Whether someone standing on a tile, even one just covered, has a step
     * off it, by the same rules as the pathfinder.
     */
    public static function canStepOff(WalkabilityGrid $grid, int $x, int $y): bool
    {
        foreach ([[0, -1], [1, 0], [0, 1], [-1, 0], [1, -1], [1, 1], [-1, 1], [-1, -1]] as [$dx, $dy]) {
            if ($grid->isWalkable($x + $dx, $y + $dy)
                && ($dx === 0 || $dy === 0 || ($grid->isWalkable($x + $dx, $y) && $grid->isWalkable($x, $y + $dy)))) {
                return true;
            }
        }

        return false;
    }
}
