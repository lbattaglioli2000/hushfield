import { describe, expect, it } from 'vite-plus/test';
import { tileToScreen } from './iso';
import { placePanel, underWall, wallPanels, wallSides } from './walls';

const ORIGIN_X = 512;

describe('wallPanels', () => {
    it('lines the back row and column, meeting behind the corner, with openings only for doors on them', () => {
        // 5 wide, 4 deep: a door on the back row, a wide one down the back column, one on the front row.
        const panels = wallPanels(5, 4, [
            { x: 3, y: 0, wide: 1, deep: 1 },
            { x: 0, y: 2, wide: 1, deep: 2 },
            { x: 2, y: 3, wide: 1, deep: 1 },
        ]);

        expect(
            panels.map(({ side, tile, doorway }) => [side, ...tile, doorway]),
        ).toEqual([
            ['left', 0, 1, false],
            ['left', 0, 2, true],
            ['left', 0, 3, true],
            ['right', 1, 0, false],
            ['right', 2, 0, false],
            ['right', 3, 0, true],
            ['right', 4, 0, false],
        ]);
    });
});

describe('one-sided walls', () => {
    it('put panels only on the named edge, and hide only its tiles, leaving the corner to the open edge', () => {
        const doors = [{ x: 0, y: 1, wide: 1, deep: 2 }];
        const left = wallSides('left');

        expect(
            wallPanels(4, 4, doors, left).map(({ side, tile, doorway }) => [
                side,
                ...tile,
                doorway,
            ]),
        ).toEqual([
            ['left', 0, 1, true],
            ['left', 0, 2, true],
            ['left', 0, 3, false],
        ]);
        expect(
            wallPanels(3, 2, [], wallSides('right')).map((p) => p.tile),
        ).toEqual([
            [1, 0],
            [2, 0],
        ]);

        expect([
            underWall([0, 2], left),
            underWall([2, 0], left),
            underWall([0, 0], left),
        ]).toEqual([true, false, false]);
        // Without the property, or with an unknown one, both walls stand and meet over the corner.
        expect(wallSides(undefined)).toEqual(['left', 'right']);
        expect(wallSides('both')).toEqual(['left', 'right']);
        expect(underWall([0, 0], wallSides(undefined))).toBe(true);
    });
});

describe('placePanel', () => {
    it("stands each panel's face on the inner edge of its tile, where the floor begins", () => {
        const height = 112;
        // The right wall's tile (3, 0): its face runs from the tile's left corner down to its bottom corner.
        const right = tileToScreen(3, 0, ORIGIN_X);
        const r = placePanel(
            { side: 'right', tile: [3, 0], doorway: false },
            height,
            ORIGIN_X,
        );
        expect([r.left, r.top + height - 16]).toEqual([right.x - 32, right.y]);

        // The left wall's tile (0, 2): its face runs from the tile's bottom corner up to its right corner.
        const left = tileToScreen(0, 2, ORIGIN_X);
        const l = placePanel(
            { side: 'left', tile: [0, 2], doorway: false },
            height,
            ORIGIN_X,
        );
        expect([l.left, l.top + height]).toEqual([left.x, left.y + 16]);
    });
});
