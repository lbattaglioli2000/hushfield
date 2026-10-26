<?php

namespace App\Domain\Assets;

/**
 * resources/art/tiles/tiles.json: every ground tile in slot order, each named
 * by its type and slab height, such as "stone/full" (the file
 * tiles/stone/full.png). Slot n is Tiled GID n + 1 in every map, so the list
 * only ever grows at the end; a retired tile becomes null and its GID may no
 * longer be drawn.
 */
final class TileRegistry
{
    /** A tile's name: its type, then its height, full or half, optionally numbered for a variant. */
    public const string NAME = '[a-z0-9-]+\/(full|half)(-[0-9]+)?';

    /** @return list<string|null> */
    public function slots(): array
    {
        $path = config('hushfield.assets.sources').'/tiles/tiles.json';
        $slots = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        if (! is_array($slots) || ! array_is_list($slots)) {
            throw new InvalidAssetException('tiles/tiles.json must be a JSON list of tiles, such as "stone/full".');
        }

        $seen = [];

        foreach ($slots as $slot) {
            if ($slot !== null && (! is_string($slot) || ! preg_match('/^'.self::NAME.'$/', $slot))) {
                throw new InvalidAssetException('tiles/tiles.json may only hold a tile type and height, such as "stone/full" or "stone/half", or null for a retired tile.');
            }

            if ($slot === null) {
                continue;
            }

            if (isset($seen[$slot])) {
                throw new InvalidAssetException("tiles/tiles.json lists {$slot} twice.");
            }

            $seen[$slot] = true;
        }

        return $slots;
    }
}
