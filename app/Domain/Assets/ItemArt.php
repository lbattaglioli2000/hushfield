<?php

namespace App\Domain\Assets;

use App\Domain\Items\ItemSlot;
use App\Domain\World\Footprint;
use App\Models\Item;

/**
 * How much of an item's art is built, for the admin item list: none, some of
 * its facings (the rest drawn as placeholders), or every facing, its own or
 * a stand-in the game may mirror or borrow (facingArt in
 * resources/js/game/shared/furniture.ts, whose rules this mirrors).
 */
final readonly class ItemArt
{
    public function __construct(private AssetIndex $assets) {}

    public function status(Item $item): ArtStatus
    {
        $index = $this->assets->current();
        $prefix = "items/{$item->slug}/";
        $parts = array_filter(array_keys($index['files']), fn (string $name): bool => str_starts_with($name, $prefix));

        if ($parts === []) {
            return ArtStatus::None;
        }

        if ($item->slot !== ItemSlot::Furniture) {
            return ArtStatus::Partial;
        }

        // A facing counts only if drawn for this item's size: (wide + deep) × 32 across at every turn.
        $width = ($item->wide + $item->deep) * 32;
        $fits = fn (int $facing): bool => ($index['sizes']["{$prefix}{$facing}"][0] ?? null) === $width;
        $drawn = count(array_filter(range(0, 3), fn (int $rotation): bool => $this->facing($item, $rotation, $fits)));

        return $drawn === 4 ? ArtStatus::Complete : ArtStatus::Partial;
    }

    /** @param  callable(int): bool  $fits */
    private function facing(Item $item, int $rotation, callable $fits): bool
    {
        $lies = fn (int $facing): Footprint => $item->footprintAt(0, 0, $facing);
        $opposite = ($rotation + 2) % 4;

        foreach ([[$rotation, false], [3 - $rotation, true], [$opposite, false], [3 - $opposite, true]] as [$facing, $flipped]) {
            $drawn = $flipped ? $this->mirrored($lies($facing)) : $lies($facing);

            if ($fits($facing) && $drawn->tiles() === $lies($rotation)->tiles() && $drawn->wide === $lies($rotation)->wide) {
                return true;
            }
        }

        return false;
    }

    /** The footprint flipped left to right on screen: x and y swap. */
    private function mirrored(Footprint $footprint): Footprint
    {
        $mask = $footprint->mask === null ? null : array_map(
            fn (int $y): string => implode('', array_map(fn (int $x): string => $footprint->mask[$x][$y], range(0, $footprint->deep - 1))),
            range(0, $footprint->wide - 1),
        );

        return new Footprint(0, 0, $footprint->deep, $footprint->wide, $mask);
    }
}
