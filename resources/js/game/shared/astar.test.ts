import { describe, expect, it } from 'vite-plus/test';
import type { Grid } from './astar';
import { findPath } from './astar';

/** Rows of '.' (walkable) and '#' (blocked); row index is y. */
function grid(...rows: string[]): Grid {
    const width = rows[0].length;
    const walkable = new Uint8Array(width * rows.length);

    rows.forEach((row, y) =>
        row.split('').forEach((cell, x) => {
            walkable[y * width + x] = cell === '.' ? 1 : 0;
        }),
    );

    return { width, height: rows.length, walkable };
}

describe('findPath', () => {
    it('walks a straight line', () => {
        expect(findPath(grid('...'), [0, 0], [2, 0], 200)).toEqual([
            [0, 0],
            [1, 0],
            [2, 0],
        ]);
    });

    it('takes the diagonal when both orthogonal neighbours are open', () => {
        expect(findPath(grid('..', '..'), [0, 0], [1, 1], 200)).toEqual([
            [0, 0],
            [1, 1],
        ]);
    });

    it('goes around a corner instead of cutting it', () => {
        expect(findPath(grid('.#', '..'), [0, 0], [1, 1], 200)).toEqual([
            [0, 0],
            [0, 1],
            [1, 1],
        ]);
    });

    it('refuses a goal reachable only by squeezing between two diagonal walls', () => {
        expect(findPath(grid('.#', '#.'), [0, 0], [1, 1], 200)).toBeNull();
    });

    it('refuses an unreachable or blocked target', () => {
        const walled = grid('.#.', '.#.', '.#.');

        expect(findPath(walled, [0, 0], [2, 2], 200)).toBeNull();
        expect(findPath(walled, [0, 0], [1, 1], 200)).toBeNull();
    });

    it('refuses a path longer than maxSteps', () => {
        expect(findPath(grid('....'), [0, 0], [3, 0], 2)).toBeNull();
        expect(findPath(grid('....'), [0, 0], [3, 0], 3)).toHaveLength(4);
    });
});
