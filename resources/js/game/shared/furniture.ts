import type { PlacedFurniture } from '../protocol/types';
import type { Grid } from './astar';
import type { Footprint } from './footprint';
import { covers, tilesOf } from './footprint';

/* How furniture is sized, turned and drawn, and its art: resources/maps/README.md. */

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

        for (const [tx, ty] of tilesOf(placedFootprint(piece))) {
            if (tx < grid.width && ty < grid.height) {
                walkable[ty * grid.width + tx] = 0;
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
