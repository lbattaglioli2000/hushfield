import type { Tile } from '../protocol/types';
import type { Footprint } from './footprint';
import { covers } from './footprint';
import { TILE_HEIGHT, TILE_WIDTH } from './iso';

/**
 * Which back wall a panel belongs to: `left` runs down the map's top-left
 * edge (along y, facing the viewer's lower right), `right` along its
 * top-right edge (along x, facing the lower left).
 */
export type WallSide = 'left' | 'right';

/** One tile's length of back wall. */
export type WallPanel = {
    side: WallSide;
    /** The tile in the back row or column the panel stands on. */
    tile: Tile;
    /** A door opens here, so the panel is a doorway. */
    doorway: boolean;
};

/**
 * The panels of a room's back walls. They stand on the map's back row
 * (y = 0, the right wall) and back column (x = 0, the left wall), their faces
 * on those tiles' inner edges where the floor begins, and meet behind the
 * corner tile (0, 0), which has no panel of its own. A door on a wall's tile
 * makes that panel a doorway.
 */
export function wallPanels(
    width: number,
    height: number,
    doors: Footprint[],
): WallPanel[] {
    const panel = (side: WallSide, tile: Tile): WallPanel => ({
        side,
        tile,
        doorway: doors.some((door) => covers(door, tile)),
    });
    const panels: WallPanel[] = [];

    for (let y = 1; y < height; y++) panels.push(panel('left', [0, y]));

    for (let x = 1; x < width; x++) panels.push(panel('right', [x, 0]));

    return panels;
}

/**
 * The world position of a panel image's top-left corner. The image is one
 * tile edge across (32 px); its face's bottom edge runs along the floor's
 * edge, from the canvas's bottom-left corner up to 16 px above its
 * bottom-right corner on the left wall, and from 16 px above its bottom-left
 * corner down to its bottom-right corner on the right wall.
 */
export function placePanel(
    { side, tile: [x, y] }: WallPanel,
    imageHeight: number,
    originX: number,
): { left: number; top: number } {
    // The floor's edge at the panel's left end: grid point (1, y + 1) or (x, 1).
    const [gx, gy] = side === 'left' ? [1, y + 1] : [x, 1];
    const bottom = (gx + gy) * (TILE_HEIGHT / 2);

    return {
        left: originX + (gx - gy) * (TILE_WIDTH / 2),
        top: bottom - imageHeight + (side === 'left' ? 0 : TILE_HEIGHT / 2),
    };
}

/** Whether a tile is under the walls: they stand on the back row and column. */
export function underWall([x, y]: Tile): boolean {
    return x === 0 || y === 0;
}
