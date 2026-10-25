import { describe, expect, it } from 'vite-plus/test';
import type { Footprint } from './buildings';
import { covers, hides, placeBuilding } from './buildings';
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
});
