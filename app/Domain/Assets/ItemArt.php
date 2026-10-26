<?php

namespace App\Domain\Assets;

use App\Domain\Items\ItemSlot;
use App\Domain\World\Footprint;
use App\Models\Item;
use Illuminate\Support\Facades\Storage;

/**
 * An item's art: for each furniture facing, its published upload from the
 * admin catalog (docs/assets-plan.md §8) or, failing that, the art built
 * from resources/art/items/{slug}/. Clients get it with the item
 * (Item::toPayload); the admin item list shows how much there is.
 * Scoped to a request, so the build's index is read once.
 */
final class ItemArt
{
    /** @var array{version: string, files: array<string, string>, sizes: array<string, array{int, int}>}|null */
    private ?array $index = null;

    public function __construct(
        private readonly AssetIndex $assets,
        private readonly AssetPublisher $publisher,
    ) {}

    /**
     * The art the game draws for each facing that has any, by facing.
     *
     * @return array<int, array{url: string, width: int}> width in game pixels
     */
    public function facings(Item $item): array
    {
        $index = $this->index ??= $this->assets->current();
        $base = $this->publisher->baseUrl($index['version']);
        $facings = [];

        foreach (range(0, 3) as $facing) {
            $name = "items/{$item->slug}/{$facing}";

            if (isset($index['files'][$name])) {
                $facings[$facing] = ['url' => "{$base}/{$index['files'][$name]}", 'width' => $index['sizes'][$name][0]];
            }
        }

        // An item not saved yet has no uploads.
        foreach ($item->exists ? $item->publishedArt : [] as $upload) {
            $facings[$upload->facing] = ['url' => $this->url($upload->path), 'width' => $upload->width];
        }

        ksort($facings);

        return $facings;
    }

    /** Where browsers fetch an upload: the public bucket's URL, or the public disk's own. */
    public function url(string $path): string
    {
        $bucket = (string) config('hushfield.assets.base_url');

        return $bucket !== ''
            ? rtrim($bucket, '/')."/{$path}"
            : Storage::disk((string) config('hushfield.assets.disk'))->url($path);
    }

    /**
     * How much of an item's art there is: none, some of its facings (the rest
     * drawn as placeholders), or every facing, its own or a stand-in the game
     * may mirror or borrow (facingArt in resources/js/game/shared/furniture.ts,
     * whose rules this mirrors).
     */
    public function status(Item $item): ArtStatus
    {
        $facings = $this->facings($item);

        if ($facings === []) {
            return ArtStatus::None;
        }

        if ($item->slot !== ItemSlot::Furniture) {
            return ArtStatus::Partial;
        }

        // A facing counts only if drawn for this item's size: (wide + deep) × 32 across at every turn.
        $width = ($item->wide + $item->deep) * 32;
        $fits = fn (int $facing): bool => ($facings[$facing]['width'] ?? null) === $width;
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
