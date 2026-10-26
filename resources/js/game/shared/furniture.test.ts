import { describe, expect, it } from 'vite-plus/test';
import type { Item, PlacedFurniture } from '../protocol/types';
import { findPath } from './astar';
import { covers, distanceTo, tilesOf } from './footprint';
import { facingArt, footprintOf, pieceAt, withFurniture } from './furniture';

function piece(
    x: number,
    y: number,
    rotation: number,
    size: { wide: number; deep: number; floor?: boolean; shape?: string[] },
): PlacedFurniture {
    return {
        piece: x * 100 + y,
        x,
        y,
        rotation,
        item: {
            id: x * 100 + y,
            slug: 'piece',
            furniture: { height: 18, floor: false, shape: null, ...size },
        } as Item,
    };
}

describe('footprintOf', () => {
    it('swaps wide and deep on a quarter turn, keeping the back corner', () => {
        const seat = { wide: 2, deep: 1 };

        expect(footprintOf(seat, 3, 4, 0)).toEqual({
            x: 3,
            y: 4,
            wide: 2,
            deep: 1,
        });
        expect(footprintOf(seat, 3, 4, 1)).toEqual({
            x: 3,
            y: 4,
            wide: 1,
            deep: 2,
        });
        expect(footprintOf(seat, 3, 4, 2)).toMatchObject({ wide: 2, deep: 1 });
        expect(footprintOf(seat, 3, 4, 3)).toMatchObject({ wide: 1, deep: 2 });
    });
});

describe('footprintOf a shaped piece', () => {
    const sofa = { wide: 2, deep: 2, shape: ['##', '#.'] };
    // A long arm along the back and a short leg at the left, 3 × 2.
    const bench = { wide: 3, deep: 2, shape: ['###', '#..'] };

    it('turns the shape a quarter turn clockwise seen from above with each turn', () => {
        expect(footprintOf(sofa, 0, 0, 1).mask).toEqual(['##', '.#']);
        expect(footprintOf(sofa, 0, 0, 2).mask).toEqual(['.#', '##']);
        expect(footprintOf(sofa, 0, 0, 3).mask).toEqual(['#.', '##']);

        // The back arm ends up down the right side, the leg at the back left.
        expect(footprintOf(bench, 3, 4, 1)).toEqual({
            x: 3,
            y: 4,
            wide: 2,
            deep: 3,
            mask: ['##', '.#', '.#'],
        });
        expect(footprintOf(bench, 3, 4, 2).mask).toEqual(['..#', '###']);
        expect(footprintOf(bench, 3, 4, 4)).toEqual(
            footprintOf(bench, 3, 4, 0),
        );
    });

    it('covers only its filled tiles', () => {
        const turned = footprintOf(bench, 3, 4, 1);

        expect(tilesOf(turned)).toEqual([
            [3, 4],
            [4, 4],
            [4, 5],
            [4, 6],
        ]);
        expect(covers(turned, [3, 5])).toBe(false);
        expect(distanceTo(turned, [3, 6])).toBe(1);
    });

    it('blocks walking only on its filled tiles, so a table fits in the corner', () => {
        const open = {
            width: 3,
            height: 3,
            walkable: new Uint8Array(9).fill(1),
        };
        const corner = piece(0, 0, 0, sofa);
        const table = piece(1, 1, 0, { wide: 1, deep: 1 });

        expect(Array.from(withFurniture(open, [corner]).walkable)).toEqual([
            0, 0, 1, 0, 1, 1, 1, 1, 1,
        ]);
        expect(pieceAt([corner, table], [1, 1])).toBe(table);
        expect(pieceAt([corner], [1, 1])).toBeNull();
    });
});

describe('withFurniture', () => {
    const open = { width: 4, height: 3, walkable: new Uint8Array(12).fill(1) };

    it('blocks the tiles under pieces, not under rugs, and leaves the map alone', () => {
        const grid = withFurniture(open, [
            piece(1, 0, 1, { wide: 2, deep: 1 }),
            piece(2, 1, 0, { wide: 2, deep: 2, floor: true }),
        ]);

        expect(Array.from(grid.walkable)).toEqual([
            1, 0, 1, 1, 1, 0, 1, 1, 1, 1, 1, 1,
        ]);
        expect(open.walkable.every((cell) => cell === 1)).toBe(true);
    });

    it('sends paths around a piece instead of through it', () => {
        const grid = withFurniture(open, [
            piece(1, 0, 0, { wide: 1, deep: 2 }),
        ]);
        const path = findPath(grid, [0, 0], [2, 0], 200);

        expect(path).not.toBeNull();
        expect(path).not.toContainEqual([1, 0]);
        expect(path).not.toContainEqual([1, 1]);
    });
});

describe('pieceAt', () => {
    it('finds the piece standing on a rug before the rug', () => {
        const rug = piece(0, 0, 0, { wide: 2, deep: 2, floor: true });
        const lamp = piece(1, 1, 0, { wide: 1, deep: 1 });

        expect(pieceAt([rug, lamp], [1, 1])).toBe(lamp);
        expect(pieceAt([rug, lamp], [0, 1])).toBe(rug);
        expect(pieceAt([rug, lamp], [2, 2])).toBeNull();
    });
});

describe('facingArt', () => {
    const art =
        (...facings: number[]) =>
        (facing: number) =>
            facings.includes(facing);

    it('uses each facing’s own image when it has one', () => {
        const all = art(0, 1, 2, 3);

        for (const rotation of [0, 1, 2, 3]) {
            expect(facingArt(rotation, all)).toEqual({
                facing: rotation,
                mirrored: false,
            });
        }
    });

    it('mirrors a single image for the quarter turns', () => {
        const one = art(0);

        expect(facingArt(0, one)).toEqual({
            facing: 0,
            mirrored: false,
        });
        expect(facingArt(1, one)).toEqual({
            facing: 0,
            mirrored: true,
        });
        expect(facingArt(2, one)).toEqual({
            facing: 0,
            mirrored: false,
        });
        expect(facingArt(3, one)).toEqual({
            facing: 0,
            mirrored: true,
        });
    });

    it('mirrors the facing that is its mirror image', () => {
        const backAndFront = art(0, 2);

        expect(facingArt(1, backAndFront)).toEqual({
            facing: 2,
            mirrored: true,
        });
        expect(facingArt(3, backAndFront)).toEqual({
            facing: 0,
            mirrored: true,
        });
    });

    it('borrows the opposite facing when there is no mirror image', () => {
        const front = art(0, 3);

        expect(facingArt(1, front)).toEqual({
            facing: 3,
            mirrored: false,
        });
        expect(facingArt(2, front)).toEqual({
            facing: 0,
            mirrored: false,
        });
    });

    it('mirrors or borrows a shaped piece’s art only onto the same tiles', () => {
        // A corner sofa mirrored or seen from behind never stands on the tiles
        // of the facing it would stand in for.
        const sofa = { wide: 2, deep: 2, shape: ['##', '#.'] };

        for (const rotation of [1, 2, 3]) {
            expect(facingArt(rotation, art(0), sofa)).toBeNull();
        }
        expect(facingArt(0, art(0), sofa)).toEqual({
            facing: 0,
            mirrored: false,
        });

        // A U-shaped booth is symmetric left to right: facing 0 mirrored
        // (#.# over ###, transposed) stands on facing 3's tiles.
        const booth = { wide: 3, deep: 2, shape: ['#.#', '###'] };

        expect(facingArt(3, art(0), booth)).toEqual({
            facing: 0,
            mirrored: true,
        });
        expect(facingArt(1, art(0), booth)).toBeNull();
        expect(facingArt(2, art(0), booth)).toBeNull();
    });

    it('has nothing to draw without art', () => {
        expect(facingArt(1, art())).toBeNull();
    });
});
