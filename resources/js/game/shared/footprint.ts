import type { Tile } from '../protocol/types';

/**
 * A block of tiles on a map: `wide` along x and `deep` along y from its back
 * corner at (x, y). Doors, fixtures, buildings and furniture each stand on
 * one, so "what is on this tile" is answered the same way for all of them.
 * Mirrors App\Domain\World\Footprint.
 */
export type Footprint = { x: number; y: number; wide: number; deep: number };

export function covers(footprint: Footprint, [x, y]: Tile): boolean {
    return (
        x >= footprint.x &&
        x < footprint.x + footprint.wide &&
        y >= footprint.y &&
        y < footprint.y + footprint.deep
    );
}

/** The covered tiles, row by row. */
export function tilesOf({ x, y, wide, deep }: Footprint): Tile[] {
    const tiles: Tile[] = [];

    for (let ty = y; ty < y + deep; ty++) {
        for (let tx = x; tx < x + wide; tx++) tiles.push([tx, ty]);
    }

    return tiles;
}

/** The middle tile (the later of the two middles along an even side). */
export function centreOf({ x, y, wide, deep }: Footprint): Tile {
    return [x + Math.floor(wide / 2), y + Math.floor(deep / 2)];
}

/** Steps from a (possibly fractional) tile position to the nearest covered tile, diagonals counting as one. */
export function distanceTo(
    { x, y, wide, deep }: Footprint,
    [px, py]: [number, number],
): number {
    return Math.max(
        Math.max(0, x - px, px - (x + wide - 1)),
        Math.max(0, y - py, py - (y + deep - 1)),
    );
}
