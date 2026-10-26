import type { PlacedFurniture } from '../protocol/types';
import type { Grid } from './astar';
import type { Footprint } from './buildings';
import { covers } from './buildings';

/*
 * Furniture art
 * -------------
 * Until a piece has art it is drawn as a placeholder block the size of its
 * footprint and `height`, tinted by its slug. To replace it, drop PNGs into
 * public/assets/v{version}/furniture/ (the current asset version,
 * HUSHFIELD_ASSET_VERSION); the asset manifest picks them up with no code change.
 *
 * - Names: `{slug}-{rotation}.png`, one per facing (0–3, see FACINGS), or a
 *   single `{slug}.png` (or `{slug}-0.png`) for facing 0. Missing facings are
 *   mirrored or borrowed, see facingArt.
 * - Canvas: (wide + deep) × 32 px across, for the footprint as it lies at that
 *   facing (a 2 × 1 love seat is 96 px across at every facing). The footprint
 *   diamond sits at the bottom of the canvas, (wide + deep) × 16 px tall, its
 *   bottom corner touching the bottom edge at x = wide × 32; the piece rises
 *   above it as tall as it needs. Nothing may hang below or beside the diamond.
 * - Front: at facing 0, the lower-left edge of the diamond.
 * - Light from the top left; 1:1 pixels with the 64 × 32 tiles; transparent background.
 *
 * Templates for 1 × 1, 2 × 1 and 2 × 2 show the diamond, its tiles and the front edge.
 * The game slices each image into strips and sorts them against avatars like
 * buildings (see resources/maps/README.md); rugs lie flat under everything.
 */

/**
 * Facings. A piece turned 0 faces the viewer's lower left (+y). Each turn is a
 * quarter turn clockwise seen from above: 1 faces the upper left (−x), 2 the
 * upper right (−y), 3 the lower right (+x).
 */
export const FACINGS = 4;

/** How big a furniture item is, before any turn. */
export type FurnitureSize = { wide: number; deep: number };

/**
 * The tiles an item covers with its back corner at (x, y), turned `rotation`
 * quarter turns: a quarter turn swaps how wide and how deep it is. Mirrors
 * App\Domain\Housing\Footprint.
 */
export function footprintOf(
    size: FurnitureSize,
    x: number,
    y: number,
    rotation: number,
): Footprint {
    return rotation % 2 === 0
        ? { x, y, wide: size.wide, deep: size.deep }
        : { x, y, wide: size.deep, deep: size.wide };
}

/** A placed piece's footprint. Items from before footprints existed stand on one tile. */
export function placedFootprint(piece: PlacedFurniture): Footprint {
    return footprintOf(
        piece.item.furniture ?? { wide: 1, deep: 1 },
        piece.x,
        piece.y,
        piece.rotation,
    );
}

/** Rugs and other floor pieces are walked over; everything else is walked around. */
export function blocksWalking(piece: PlacedFurniture): boolean {
    return !piece.item.furniture?.floor;
}

/**
 * Where players may walk in a home: the map's grid minus the tiles under
 * blocking furniture, as the server checks it. The map's grid is left as is.
 */
export function withFurniture(grid: Grid, placed: PlacedFurniture[]): Grid {
    const walkable = grid.walkable.slice();

    for (const piece of placed) {
        if (!blocksWalking(piece)) continue;

        const { x, y, wide, deep } = placedFootprint(piece);

        for (let ty = y; ty < y + deep; ty++) {
            for (let tx = x; tx < x + wide; tx++) {
                if (tx < grid.width && ty < grid.height) {
                    walkable[ty * grid.width + tx] = 0;
                }
            }
        }
    }

    return { ...grid, walkable };
}

/**
 * The piece standing on a tile, if any: the one on top when a piece stands on a rug.
 */
export function pieceAt(
    placed: PlacedFurniture[],
    tile: [number, number],
): PlacedFurniture | null {
    const here = placed.filter((piece) => covers(placedFootprint(piece), tile));

    return here.find(blocksWalking) ?? here[0] ?? null;
}

/**
 * Which image to draw a piece from, and whether to mirror it.
 *
 * Art is named by item slug: `{slug}-{rotation}.png` for each facing, or just
 * `{slug}.png` for facing 0 (see Furniture art above). A facing without
 * its own image mirrors its mirror image: flipping an isometric picture left
 * to right turns lower left into lower right and upper left into upper
 * right, so facing r mirrors facing 3 − r. Failing that it borrows the
 * opposite facing, the same shape seen from the wrong side, or mirrors that.
 * Null when there is no art at all, and a placeholder is drawn instead.
 */
export function facingArt(
    slug: string,
    rotation: number,
    has: (name: string) => boolean,
): { name: string; mirrored: boolean } | null {
    const named = (facing: number) =>
        [`${slug}-${facing}`, ...(facing === 0 ? [slug] : [])].find(has) ??
        null;
    const opposite = (rotation + 2) % FACINGS;
    const tries: Array<[number, boolean]> = [
        [rotation, false],
        [3 - rotation, true],
        [opposite, false],
        [3 - opposite, true],
    ];

    for (const [facing, mirrored] of tries) {
        const name = named(facing);

        if (name) return { name, mirrored };
    }

    return null;
}
