import type { Tile } from '../protocol/types';

/**
 * A block of tiles on a map: `wide` along x and `deep` along y from its back
 * corner at (x, y). Doors, fixtures, buildings and furniture each stand on
 * one, so "what is on this tile" is answered the same way for all of them.
 *
 * A footprint may have a shape, `mask`: one row per tile along y (the first
 * row is the back row), one character per tile along x, '#' for a covered
 * tile and '.' for an empty one, so an L-shaped sofa 2 × 2 is ['##', '#.'].
 * Empty tiles are not covered at all. Without a mask the whole block is.
 * Mirrors App\Domain\World\Footprint.
 */
export type Footprint = {
    x: number;
    y: number;
    wide: number;
    deep: number;
    mask?: string[] | null;
};

const FILLED = '#';

/** Whether the tile (dx, dy) from the back corner, inside the block, is covered. */
function fills({ mask }: Footprint, dx: number, dy: number): boolean {
    return !mask || mask[dy][dx] === FILLED;
}

export function covers(footprint: Footprint, [x, y]: Tile): boolean {
    return (
        x >= footprint.x &&
        x < footprint.x + footprint.wide &&
        y >= footprint.y &&
        y < footprint.y + footprint.deep &&
        fills(footprint, x - footprint.x, y - footprint.y)
    );
}

/** The covered tiles, row by row. */
export function tilesOf(footprint: Footprint): Tile[] {
    const { x, y, wide, deep } = footprint;
    const tiles: Tile[] = [];

    for (let dy = 0; dy < deep; dy++) {
        for (let dx = 0; dx < wide; dx++) {
            if (fills(footprint, dx, dy)) tiles.push([x + dx, y + dy]);
        }
    }

    return tiles;
}

/** The middle tile (the later of the two middles along an even side). */
export function centreOf({ x, y, wide, deep }: Footprint): Tile {
    return [x + Math.floor(wide / 2), y + Math.floor(deep / 2)];
}

/** Steps from a (possibly fractional) tile position to the nearest covered tile, diagonals counting as one. */
export function distanceTo(
    footprint: Footprint,
    [px, py]: [number, number],
): number {
    const { x, y, wide, deep, mask } = footprint;

    if (!mask) {
        return Math.max(
            Math.max(0, x - px, px - (x + wide - 1)),
            Math.max(0, y - py, py - (y + deep - 1)),
        );
    }

    return Math.min(
        ...tilesOf(footprint).map(([tx, ty]) =>
            Math.max(Math.abs(tx - px), Math.abs(ty - py)),
        ),
    );
}

/**
 * The same footprint turned `rotation` quarter turns clockwise seen from
 * above, about its back corner: what faced +y faces −x after one turn. The
 * block swaps how wide and how deep it is on every odd turn, and its mask
 * turns with it: tile (dx, dy) of the turned block was (dy, deep − 1 − dx).
 */
export function turned(footprint: Footprint, rotation: number): Footprint {
    let result = footprint;

    for (let turn = 0; turn < ((rotation % 4) + 4) % 4; turn++) {
        const { x, y, wide, deep, mask } = result;

        result = {
            x,
            y,
            wide: deep,
            deep: wide,
            mask: mask
                ? Array.from({ length: wide }, (_, dy) =>
                      Array.from(
                          { length: deep },
                          (_, dx) => mask[deep - 1 - dx][dy],
                      ).join(''),
                  )
                : mask,
        };
    }

    return result;
}

/**
 * The footprint of a picture of this one flipped left to right. On screen x
 * runs to the lower right and y to the lower left, so flipping swaps them:
 * the mask is transposed, and wide and deep swap.
 */
export function mirrored(footprint: Footprint): Footprint {
    const { x, y, wide, deep, mask } = footprint;

    return {
        x,
        y,
        wide: deep,
        deep: wide,
        mask: mask
            ? Array.from({ length: wide }, (_, dy) =>
                  Array.from({ length: deep }, (_, dx) => mask[dx][dy]).join(
                      '',
                  ),
              )
            : mask,
    };
}

/** Whether two footprints cover the same tiles relative to their back corners. */
export function sameShape(a: Footprint, b: Footprint): boolean {
    const shape = (f: Footprint) =>
        tilesOf({ ...f, x: 0, y: 0 })
            .map((tile) => tile.join(','))
            .join(' ');

    return a.wide === b.wide && a.deep === b.deep && shape(a) === shape(b);
}
