import type { Tile } from '../protocol/types';
import { TILE_HEIGHT, TILE_WIDTH } from './iso';

/** A building's tiles: `wide` along x and `deep` along y from its back corner at (x, y). */
export type Footprint = { x: number; y: number; wide: number; deep: number };

/** One vertical slice of a building's image, sorted against avatars on its own. */
export type Strip = {
    /** Left edge within the image, in pixels. */
    offsetX: number;
    width: number;
    /**
     * World y of the footprint's front edge under the strip's centre. An avatar
     * whose tile centre is lower on screen than this stands in front of the strip.
     */
    depthY: number;
};

/** Where a building's image goes in the world, and how it is sliced. */
export type Placement = {
    left: number;
    top: number;
    width: number;
    height: number;
    strips: Strip[];
};

const HALF_W = TILE_WIDTH / 2;
const HALF_H = TILE_HEIGHT / 2;

export function covers(footprint: Footprint, [x, y]: Tile): boolean {
    return (
        x >= footprint.x &&
        x < footprint.x + footprint.wide &&
        y >= footprint.y &&
        y < footprint.y + footprint.deep
    );
}

/**
 * Lays an image (wide + deep) × 32 px across over the footprint, bottom corner
 * on the footprint's bottom corner, and cuts it into strips half a tile wide.
 *
 * One depth for the whole image is wrong for anything longer than a tile: an
 * avatar beside the far end of a long wall would be sorted against its near
 * end. Each strip only spans the tiles directly below it, so the front edge
 * under its centre says who is in front.
 */
export function placeBuilding(
    footprint: Footprint,
    imageHeight: number,
    originX: number,
): Placement {
    const { x, y, wide, deep } = footprint;
    const width = (wide + deep) * HALF_W;
    // The footprint's bottom corner, the grid point (x + wide, y + deep).
    const bottomX = originX + (x + wide - (y + deep)) * HALF_W;
    const bottomY = (x + wide + y + deep) * HALF_H;
    const left = bottomX - wide * HALF_W;

    return {
        left,
        top: bottomY - imageHeight,
        width,
        height: imageHeight,
        strips: Array.from({ length: wide + deep }, (_, i) => {
            const centreX = left + i * HALF_W + HALF_W / 2;

            // The front edge rises half a pixel per pixel away from the bottom corner.
            return {
                offsetX: i * HALF_W,
                width: HALF_W,
                depthY: bottomY - Math.abs(centreX - bottomX) / 2,
            };
        }),
    };
}

/**
 * Whether any strip of the building is drawn over a box (such as an avatar's
 * sprite) sorted at `depthY`.
 */
export function hides(
    placement: Placement,
    box: { left: number; right: number; top: number; bottom: number },
    depthY: number,
): boolean {
    if (
        box.bottom <= placement.top ||
        box.top >= placement.top + placement.height
    ) {
        return false;
    }

    return placement.strips.some(
        (strip) =>
            strip.depthY > depthY &&
            box.right > placement.left + strip.offsetX &&
            box.left < placement.left + strip.offsetX + strip.width,
    );
}

/**
 * Whether a building should fade to show the player behind it. The door is
 * in the footprint's front row, so a player stepping into it is drawn among
 * the walls beside it; that is walking in, not being hidden, so anywhere
 * within a tile of the door never fades the building.
 */
export function hidesPlayer(
    placement: Placement,
    door: Tile,
    player: {
        /** The player's (possibly fractional) tile position. */
        at: { x: number; y: number };
        box: { left: number; right: number; top: number; bottom: number };
        depthY: number;
    },
): boolean {
    const inDoorway =
        Math.max(
            Math.abs(player.at.x - door[0]),
            Math.abs(player.at.y - door[1]),
        ) < 1;

    return !inDoorway && hides(placement, player.box, player.depthY);
}
