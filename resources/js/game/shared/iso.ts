import type { Tile } from '../protocol/types';

/** Isometric diamond tiles, 64×32. Tile (0,0) is the top corner of the map. */
export const TILE_WIDTH = 64;
export const TILE_HEIGHT = 32;

/**
 * Ground tile images are raised blocks: TILE_WIDTH wide and TILE_IMAGE_HEIGHT tall,
 * with the walkable top face starting TILE_FACE_TOP px down and the block's sides below it.
 */
export const TILE_IMAGE_HEIGHT = 64;
export const TILE_FACE_TOP = 16;

/** Centre of a (possibly fractional) tile in world pixels, given the map origin x. */
export function tileToScreen(
    x: number,
    y: number,
    originX: number,
): { x: number; y: number } {
    return {
        x: originX + (x - y) * (TILE_WIDTH / 2),
        y: (x + y) * (TILE_HEIGHT / 2) + TILE_HEIGHT / 2,
    };
}

/** The tile under a world-pixel point. */
export function screenToTile(px: number, py: number, originX: number): Tile {
    const sx = (px - originX) / (TILE_WIDTH / 2);
    const sy = py / (TILE_HEIGHT / 2);

    return [Math.floor((sy + sx) / 2), Math.floor((sy - sx) / 2)];
}
