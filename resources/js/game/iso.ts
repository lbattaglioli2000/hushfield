/** Isometric diamond tiles, 64×32. Tile (0,0) is the top corner of the map. */
export const TILE_WIDTH = 64;
export const TILE_HEIGHT = 32;

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

/** The tile under a world-pixel point, or fractional coordinates when `round` is false. */
export function screenToTile(
    px: number,
    py: number,
    originX: number,
): [number, number] {
    const sx = (px - originX) / (TILE_WIDTH / 2);
    const sy = py / (TILE_HEIGHT / 2);

    return [Math.floor((sy + sx) / 2), Math.floor((sy - sx) / 2)];
}
