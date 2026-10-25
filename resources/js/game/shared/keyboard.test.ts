import { describe, expect, it } from 'vite-plus/test';
import type { Grid } from './astar';
import { directionOf, straightLine } from './keyboard';

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

describe('directionOf', () => {
    it('maps single keys to screen directions', () => {
        expect(directionOf(['ArrowUp'])).toEqual([-1, -1]);
        expect(directionOf(['KeyS'])).toEqual([1, 1]);
        expect(directionOf(['KeyA'])).toEqual([-1, 1]);
        expect(directionOf(['ArrowRight'])).toEqual([1, -1]);
    });

    it('combines two keys', () => {
        expect(directionOf(['ArrowUp', 'ArrowRight'])).toEqual([0, -1]);
        expect(directionOf(['KeyW', 'KeyA'])).toEqual([-1, 0]);
        expect(directionOf(['KeyS', 'KeyD'])).toEqual([1, 0]);
        expect(directionOf(['ArrowDown', 'KeyA'])).toEqual([0, 1]);
    });

    it('cancels opposite keys', () => {
        expect(directionOf(['ArrowUp', 'KeyS'])).toBeNull();
        expect(directionOf(['KeyA', 'KeyD', 'KeyW'])).toEqual([-1, -1]);
    });

    it('does not double a direction held on both key sets', () => {
        expect(directionOf(['KeyW', 'ArrowUp', 'KeyD'])).toEqual([0, -1]);
    });

    it('ignores other keys', () => {
        expect(directionOf(['Enter', 'KeyQ'])).toBeNull();
    });
});

describe('straightLine', () => {
    it('stops before a wall partway along the line', () => {
        expect(straightLine(grid('...#..'), [0, 0], [1, 0], 8)).toEqual([
            [0, 0],
            [1, 0],
            [2, 0],
        ]);
    });

    it('does not path around obstacles', () => {
        expect(straightLine(grid('.#.', '...'), [0, 0], [1, 0], 8)).toEqual([
            [0, 0],
        ]);
    });

    it('caps the line at maxSteps', () => {
        expect(straightLine(grid('......'), [5, 0], [-1, 0], 3)).toEqual([
            [5, 0],
            [4, 0],
            [3, 0],
            [2, 0],
        ]);
    });

    it('walks diagonals while both orthogonal neighbours are open', () => {
        expect(
            straightLine(
                grid('....', '....', '...#', '..#.'),
                [0, 0],
                [1, 1],
                8,
            ),
        ).toEqual([
            [0, 0],
            [1, 1],
            [2, 2],
        ]);
    });

    it('refuses to cut a corner on a diagonal', () => {
        // (1,1) is open but (1,0) is a wall, so the step from (0,0) to (1,1) cuts its corner.
        expect(straightLine(grid('.#', '..'), [0, 0], [1, 1], 8)).toEqual([
            [0, 0],
        ]);
        // The other way round the same corner is blocked too.
        expect(straightLine(grid('..', '#.'), [0, 0], [1, 1], 8)).toEqual([
            [0, 0],
        ]);
    });

    it('stops at the grid edge', () => {
        expect(straightLine(grid('...', '...'), [1, 1], [-1, -1], 8)).toEqual([
            [1, 1],
            [0, 0],
        ]);
    });
});
