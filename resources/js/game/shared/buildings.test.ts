import { describe, expect, it } from 'vite-plus/test';
import {
    eavesLights,
    hides,
    hidesPlayer,
    onRoof,
    placeBuilding,
} from './buildings';
import type { Footprint } from './footprint';
import { covers } from './footprint';
import { tileToScreen } from './iso';

const ORIGIN_X = 512;

describe('placeBuilding', () => {
    it('puts the bottom of a (wide + deep) × 32 image on the footprint', () => {
        // Tiles x 5–11, y 1–5. Its left corner is grid point (5, 6), its bottom corner (12, 6).
        const placement = placeBuilding(
            { x: 5, y: 1, wide: 7, deep: 5 },
            200,
            ORIGIN_X,
        );

        expect(placement).toMatchObject({
            left: ORIGIN_X + (5 - 6) * 32,
            top: (12 + 6) * 16 - 200,
            width: 384,
            height: 200,
        });
        expect(placement.strips).toHaveLength(12);
        expect(placement.strips.map((strip) => strip.offsetX)).toEqual(
            Array.from({ length: 12 }, (_, i) => i * 32),
        );
    });
});

describe('hides', () => {
    /** A 28 × 44 avatar sprite standing on a tile, sorted by its tile centre like AvatarView. */
    function avatarOn(x: number, y: number) {
        const centre = tileToScreen(x, y, ORIGIN_X);

        return {
            box: {
                left: centre.x - 14,
                right: centre.x + 14,
                top: centre.y - 40,
                bottom: centre.y + 4,
            },
            depthY: centre.y,
        };
    }

    // Long buildings are where a single sort depth goes wrong, so test both ways round.
    it.each<Footprint>([
        { x: 4, y: 3, wide: 9, deep: 2 },
        { x: 3, y: 2, wide: 2, deep: 8 },
        { x: 4, y: 4, wide: 3, deep: 3 },
    ])(
        'hides avatars behind the walls and none in front, at %o',
        (footprint) => {
            const placement = placeBuilding(footprint, 120, ORIGIN_X);
            const right = footprint.x + footprint.wide;
            const front = footprint.y + footprint.deep;

            for (let x = footprint.x - 3; x < right + 3; x++) {
                for (let y = footprint.y - 3; y < front + 3; y++) {
                    if (covers(footprint, [x, y])) continue;

                    const inFront = x >= right || y >= front;
                    const behind = x < footprint.x || y < footprint.y;
                    const { box, depthY } = avatarOn(x, y);
                    const overlaps =
                        box.right > placement.left &&
                        box.left < placement.left + placement.width &&
                        box.bottom > placement.top;

                    // Off to the far left or right; no wall is near the avatar either way.
                    if (inFront && behind) {
                        expect(overlaps, `(${x}, ${y})`).toBe(false);
                        continue;
                    }

                    expect(
                        hides(placement, box, depthY),
                        `avatar at (${x}, ${y})`,
                    ).toBe(behind && overlaps);
                }
            }
        },
    );

    describe('around an L-shaped piece', () => {
        // 26 px tall, so (2 + 2) × 16 px of footprint below that.
        const HEIGHT = 26;
        const imageHeight = HEIGHT + 64;

        /** The strip drawing a point of the image, by world position. */
        function stripAt(
            placement: ReturnType<typeof placeBuilding>,
            point: { x: number; y: number },
        ) {
            return placement.strips.find(
                (strip) =>
                    point.x >= placement.left + strip.offsetX &&
                    point.x < placement.left + strip.offsetX + strip.width &&
                    point.y >= placement.top + strip.offsetY &&
                    point.y < placement.top + strip.offsetY + strip.height,
            );
        }

        /** A point on a tile's top face, just right of its middle. */
        function topOf(x: number, y: number) {
            const centre = tileToScreen(x, y, ORIGIN_X);

            return { x: centre.x + 8, y: centre.y - HEIGHT };
        }

        it('sorts an avatar in its inner corner in front of the back arm and behind the side arm', () => {
            // #. over ##: the empty corner (5, 3) is on the right, between the
            // back arm's tile (4, 3) and the side arm's (5, 4) straight down the screen.
            const placement = placeBuilding(
                { x: 4, y: 3, wide: 2, deep: 2, mask: ['#.', '##'] },
                imageHeight,
                ORIGIN_X,
            );
            const { box, depthY } = avatarOn(5, 3);
            const backArm = stripAt(placement, topOf(4, 3));
            const sideArm = stripAt(placement, topOf(5, 4));

            expect(backArm).toBeDefined();
            expect(sideArm).toBeDefined();
            expect(backArm).not.toBe(sideArm);
            expect(backArm!.depthY).toBeLessThan(depthY);
            expect(sideArm!.depthY).toBeGreaterThan(depthY);
            expect(hides(placement, box, depthY)).toBe(true);

            // The foot of the back arm's lower-right face, right beside the
            // corner, sorts with the back arm too, not with the side arm in front.
            const centre = tileToScreen(4, 3, ORIGIN_X);
            const foot = stripAt(placement, {
                x: centre.x + 28,
                y: centre.y - 1,
            });

            expect(foot!.depthY).toBeLessThan(depthY);
        });

        it('draws an avatar in an empty front corner over the piece, and one in an empty back corner under it', () => {
            const front = placeBuilding(
                { x: 4, y: 3, wide: 2, deep: 2, mask: ['##', '#.'] },
                imageHeight,
                ORIGIN_X,
            );
            const back = placeBuilding(
                { x: 4, y: 3, wide: 2, deep: 2, mask: ['.#', '##'] },
                imageHeight,
                ORIGIN_X,
            );
            const inFront = avatarOn(5, 4);
            const behind = avatarOn(4, 3);

            expect(hides(front, inFront.box, inFront.depthY)).toBe(false);
            expect(hides(back, behind.box, behind.depthY)).toBe(true);
        });

        it('leaves a plain block in whole strips', () => {
            const placement = placeBuilding(
                { x: 4, y: 3, wide: 2, deep: 2 },
                imageHeight,
                ORIGIN_X,
            );

            expect(placement.strips).toHaveLength(4);
            expect(
                placement.strips.every(
                    (strip) =>
                        strip.offsetY === 0 && strip.height === imageHeight,
                ),
            ).toBe(true);
        });
    });

    it('ignores avatars well above or below the image', () => {
        const placement = placeBuilding(
            { x: 4, y: 4, wide: 3, deep: 3 },
            60,
            ORIGIN_X,
        );
        const { box } = avatarOn(2, 2);

        expect(
            hides(placement, { ...box, top: 0, bottom: placement.top }, 0),
        ).toBe(false);
    });

    it('never fades a building for a player walking in its door, but still does behind it', () => {
        // The Library: tiles x 4–10, y 1–5, door x 6–8 in the front row (y 5), doorstep (7, 6).
        const footprint = { x: 4, y: 1, wide: 7, deep: 5 };
        const door = { x: 6, y: 5, wide: 3, deep: 1 };
        const placement = placeBuilding(footprint, 176, ORIGIN_X);
        const player = (x: number, y: number) => ({
            at: { x, y },
            ...avatarOn(x, y),
        });

        // Halfway into the doorway the walls beside it are drawn over the avatar.
        expect(
            hides(placement, avatarOn(7, 5.25).box, avatarOn(7, 5.25).depthY),
        ).toBe(true);

        // Into any of the door's tiles, the ends of a wide door as much as its middle.
        for (const x of [6, 7, 8]) {
            for (const y of [6, 5.75, 5.5, 5.25, 5]) {
                expect(
                    hidesPlayer(placement, door, player(x, y)),
                    `(${x}, ${y})`,
                ).toBe(false);
            }
        }

        expect(
            hides(placement, avatarOn(6, 5.25).box, avatarOn(6, 5.25).depthY),
        ).toBe(true);
        expect(hidesPlayer(placement, door, player(7, 0))).toBe(true);
        expect(hidesPlayer(placement, door, player(3, 3))).toBe(true);
    });
});

describe('on a building', () => {
    // The Library: tiles x 4–10, y 1–5, 96 tall. Its left corner is grid point (4, 6), its bottom corner (11, 6).
    const library = { x: 4, y: 1, wide: 7, deep: 5, height: 96 };
    const below = placeBuilding(library, library.height, ORIGIN_X);
    // The depth of the building's strip at world x, and of an avatar standing on a tile.
    const stripDepth = (x: number) =>
        Math.max(
            ...below.strips
                .filter(
                    (s) =>
                        below.left + s.offsetX <= x &&
                        x < below.left + s.offsetX + s.width,
                )
                .map((s) => s.depthY),
        );
    const avatarDepth = (x: number, y: number) =>
        tileToScreen(x, y, ORIGIN_X).y;

    it('strings a strand along each tile of the two front eaves, sorting just in front of the wall below it', () => {
        const lights = eavesLights(library, ORIGIN_X);

        expect(lights.map((light) => light.side)).toEqual([
            ...Array<string>(7).fill('right'),
            ...Array<string>(5).fill('left'),
        ]);
        // Along the south-west face from its left corner, falling 16 a tile.
        expect(lights[0]).toMatchObject({
            x: ORIGIN_X + (4 - 6) * 32,
            y: (4 + 6) * 16 - 96,
        });
        expect(lights[6]).toMatchObject({
            x: ORIGIN_X + (10 - 6) * 32,
            y: (10 + 6) * 16 - 96,
        });
        // Then up the south-east face from its bottom corner, each frame hung from its higher, right end.
        expect(lights[7]).toMatchObject({
            x: ORIGIN_X + (11 - 6) * 32,
            y: (11 + 5) * 16 - 96,
        });
        expect(lights[11]).toMatchObject({
            x: ORIGIN_X + (11 - 2) * 32,
            y: (11 + 1) * 16 - 96,
        });

        for (const light of lights) {
            expect(light.depthY).toBeGreaterThan(stripDepth(light.x + 16));
        }
        // Under anyone standing in front of the building.
        expect(lights[2].depthY).toBeLessThan(avatarDepth(6, 6));
        expect(lights[9].depthY).toBeLessThan(avatarDepth(11, 3));
    });

    it('lifts a piece onto the roof, over the building but under anyone in front of it', () => {
        // The sleigh, 5 × 1, on the middle of the roof: x 5–9, y 3.
        const sleigh = placeBuilding(
            { x: 5, y: 3, wide: 5, deep: 1 },
            80,
            ORIGIN_X,
        );
        const lifted = onRoof(sleigh, library, ORIGIN_X);

        expect(lifted.top).toBe(sleigh.top - 96);

        for (const strip of lifted.strips) {
            const x = lifted.left + strip.offsetX + strip.width / 2;

            // Its own front edge is behind the building's walls; on the roof it draws over them.
            expect(stripDepth(x)).toBeGreaterThan(
                sleigh.strips[lifted.strips.indexOf(strip)].depthY,
            );
            expect(strip.depthY).toBeGreaterThan(stripDepth(x));
            // Only just: anything sorting in front of the building also sorts in front of the piece.
            expect(strip.depthY).toBeLessThan(stripDepth(x) + 0.01);
        }
    });
});
