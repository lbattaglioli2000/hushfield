<?php

namespace App\Domain\Assets;

/**
 * resources/art/props/props.json: every room piece, such as the Coin
 * Laundry's washers, in slot order, each with the tiles it covers before any
 * turn. Room pieces belong to a room's map, not to anyone's inventory: they
 * are placed in Tiled from the "props" tileset the build writes into each map
 * with a "props" layer. Slot n's tiles are 8n to 8n + 7: facing f is
 * 8n + f, and 8n + 4 + f is the same facing switched on, its animation
 * playing (only for a piece with `frame_ms`), so the list only ever grows at
 * the end; a retired piece becomes null.
 */
final class PropRegistry
{
    /** Tiles each slot takes in the props tileset: four facings, off and on. */
    public const int TILES = 8;

    /** The largest block a piece may cover each way, as for furniture shapes. */
    private const int MAX_TILES = 8;

    /** @return list<array{slug: string, wide: int, deep: int, frame_ms?: int|list<int>}|null> */
    public function slots(): array
    {
        $path = config('hushfield.assets.sources').'/props/props.json';

        if (! is_file($path)) {
            return [];
        }

        $slots = json_decode((string) file_get_contents($path), true);

        if (! is_array($slots) || ! array_is_list($slots)) {
            throw new InvalidAssetException('props/props.json must be a JSON list of room pieces, such as {"slug": "washer", "wide": 1, "deep": 1}.');
        }

        $seen = [];

        foreach ($slots as $slot) {
            if ($slot === null) {
                continue;
            }

            $valid = is_array($slot) && is_string($slot['slug'] ?? null) && preg_match('/^[a-z0-9-]+$/', $slot['slug'])
                && array_all(['wide', 'deep'], fn (string $key): bool => is_int($slot[$key] ?? null) && $slot[$key] >= 1 && $slot[$key] <= self::MAX_TILES);

            if (! $valid) {
                throw new InvalidAssetException('props/props.json may only hold room pieces, each a slug and the tiles it covers, 1 to '.self::MAX_TILES.' each way, such as {"slug": "washer", "wide": 1, "deep": 1}, or null for a retired piece.');
            }

            if (isset($seen[$slot['slug']])) {
                throw new InvalidAssetException("props/props.json lists {$slot['slug']} twice.");
            }

            $times = $slot['frame_ms'] ?? null;

            if (array_key_exists('frame_ms', $slot) && ! (is_int($times) && $times > 0)
                && ! (is_array($times) && $times !== [] && array_is_list($times) && array_all($times, fn (mixed $ms): bool => is_int($ms) && $ms > 0))) {
                throw new InvalidAssetException("props/props.json gives {$slot['slug']} frame times it can't use. \"frame_ms\": 200 shows every frame of its animation for 200 ms; a list gives one time for each frame.");
            }

            $seen[$slot['slug']] = true;
        }

        /** @var list<array{slug: string, wide: int, deep: int, frame_ms?: int|list<int>}|null> $slots */
        return $slots;
    }

    /**
     * How a piece lies at a facing: a quarter turn swaps how wide and how deep it is.
     *
     * @param  array{slug: string, wide: int, deep: int}  $prop
     * @return array{int, int}
     */
    public static function lies(array $prop, int $facing): array
    {
        return $facing % 2 === 0 ? [$prop['wide'], $prop['deep']] : [$prop['deep'], $prop['wide']];
    }
}
