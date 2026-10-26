import type { PlacedFurniture } from '../protocol/types';
import type { Grid } from './astar';
import type { Footprint } from './footprint';
import { covers, mirrored, sameShape, tilesOf, turned } from './footprint';

/* How furniture is sized, turned and drawn, and its art: resources/maps/README.md. */

/**
 * Facings. A piece turned 0 faces the viewer's lower left (+y). Each turn is a
 * quarter turn clockwise seen from above: 1 faces the upper left (−x), 2 the
 * upper right (−y), 3 the lower right (+x).
 */
export const FACINGS = 4;

/** How big a furniture item is, and its shape (a Footprint mask) if it is not a plain block, before any turn. */
export type FurnitureSize = {
    wide: number;
    deep: number;
    shape?: string[] | null;
};

/**
 * The tiles an item covers with its back corner at (x, y), turned `rotation`
 * quarter turns: a quarter turn swaps how wide and how deep it is, and turns
 * its shape. Mirrors Item::footprintAt.
 */
export function footprintOf(
    size: FurnitureSize,
    x: number,
    y: number,
    rotation: number,
): Footprint {
    return turned(
        { x, y, wide: size.wide, deep: size.deep, mask: size.shape },
        rotation,
    );
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
 * Which facing's image to draw a piece from, and whether to mirror it.
 *
 * Each item carries its art by facing (`item.furniture.art`): uploaded in the
 * admin catalog, or built from resources/art/items/{slug}/{facing}.png
 * (resources/maps/README.md, "Art"). A facing without its own image mirrors
 * its mirror image: flipping an isometric picture left to right turns lower
 * left into lower right and upper left into upper right, so facing r mirrors
 * facing 3 − r. Failing that it borrows the opposite facing, the same shape
 * seen from the wrong side, or mirrors that.
 *
 * A stand-in must stand on the same tiles. A plain block always does; a
 * shaped one (an L, say) only when the borrowed image, flipped if it is,
 * covers the same tiles as the piece as it is turned, so most shapes need
 * their own image for most facings. Null when nothing fits, and a
 * placeholder is drawn instead.
 */
export function facingArt(
    rotation: number,
    has: (facing: number) => boolean,
    size: FurnitureSize = { wide: 1, deep: 1 },
): { facing: number; mirrored: boolean } | null {
    const lies = (facing: number) => footprintOf(size, 0, 0, facing);
    const opposite = (rotation + 2) % FACINGS;
    const tries: Array<[number, boolean]> = [
        [rotation, false],
        [3 - rotation, true],
        [opposite, false],
        [3 - opposite, true],
    ];

    for (const [facing, flipped] of tries) {
        const drawn = flipped ? mirrored(lies(facing)) : lies(facing);

        if (has(facing) && sameShape(drawn, lies(rotation))) {
            return { facing, mirrored: flipped };
        }
    }

    return null;
}
