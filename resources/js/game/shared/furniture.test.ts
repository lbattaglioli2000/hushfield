import { describe, expect, it } from 'vite-plus/test';
import type { Item, PlacedFurniture } from '../protocol/types';
import { findPath } from './astar';
import { facingArt, footprintOf, pieceAt, withFurniture } from './furniture';

function piece(
    x: number,
    y: number,
    rotation: number,
    size: { wide: number; deep: number; floor?: boolean },
): PlacedFurniture {
    return {
        x,
        y,
        rotation,
        item: {
            id: x * 100 + y,
            slug: 'piece',
            furniture: { height: 18, floor: false, ...size },
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
        (...names: string[]) =>
        (name: string) =>
            names.includes(name);

    it('uses each facing’s own image when it has one', () => {
        const all = art('bed-0', 'bed-1', 'bed-2', 'bed-3');

        for (const rotation of [0, 1, 2, 3]) {
            expect(facingArt('bed', rotation, all)).toEqual({
                name: `bed-${rotation}`,
                mirrored: false,
            });
        }
    });

    it('mirrors a single image for the quarter turns', () => {
        const one = art('bed');

        expect(facingArt('bed', 0, one)).toEqual({
            name: 'bed',
            mirrored: false,
        });
        expect(facingArt('bed', 1, one)).toEqual({
            name: 'bed',
            mirrored: true,
        });
        expect(facingArt('bed', 2, one)).toEqual({
            name: 'bed',
            mirrored: false,
        });
        expect(facingArt('bed', 3, one)).toEqual({
            name: 'bed',
            mirrored: true,
        });
    });

    it('mirrors the facing that is its mirror image', () => {
        const backAndFront = art('bed-0', 'bed-2');

        expect(facingArt('bed', 1, backAndFront)).toEqual({
            name: 'bed-2',
            mirrored: true,
        });
        expect(facingArt('bed', 3, backAndFront)).toEqual({
            name: 'bed-0',
            mirrored: true,
        });
    });

    it('borrows the opposite facing when there is no mirror image', () => {
        const front = art('bed-0', 'bed-3');

        expect(facingArt('bed', 1, front)).toEqual({
            name: 'bed-3',
            mirrored: false,
        });
        expect(facingArt('bed', 2, front)).toEqual({
            name: 'bed-0',
            mirrored: false,
        });
    });

    it('has nothing to draw without art', () => {
        expect(facingArt('bed', 1, art('chair'))).toBeNull();
    });
});
