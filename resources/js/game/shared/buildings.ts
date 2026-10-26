import type { Footprint } from './footprint';
import { covers } from './footprint';
import { TILE_HEIGHT, TILE_WIDTH } from './iso';

/**
 * One piece of a building's image, sorted against avatars on its own: a
 * vertical slice half a tile wide, or part of one where a shaped footprint
 * leaves a gap along it.
 */
export type Strip = {
    /** Left edge within the image, in pixels. */
    offsetX: number;
    /** Top edge within the image, in pixels. */
    offsetY: number;
    width: number;
    height: number;
    /**
     * World y of the front edge, under the strip's centre, of the tiles it
     * draws. An avatar whose tile centre is lower on screen than this stands
     * in front of the strip.
     */
    depthY: number;
};

/** Where a building's image goes in the world, and how it is sliced. */
export type Placement = {
    left: number;
    top: number;
    width: number;
    height: number;
    strips: Strip[];
};

const HALF_W = TILE_WIDTH / 2;
const HALF_H = TILE_HEIGHT / 2;
/** How many slices a strip with a gap in its tiles is cut into, 8 px each. */
const GAP_SLICES = 4;

/**
 * Lays an image (wide + deep) × 32 px across over the footprint, bottom corner
 * on the footprint's bottom corner, and cuts it into strips half a tile wide.
 *
 * One depth for the whole image is wrong for anything longer than a tile: an
 * avatar beside the far end of a long wall would be sorted against its near
 * end. Each strip only spans the tiles directly below it, so the front edge
 * of those tiles under its centre says who is in front.
 *
 * A shaped footprint can leave a gap along a strip, as the inner corner of
 * an L does: an arm behind the gap and an arm in front of it, and someone
 * standing in it is between the two. Such a strip is cut into narrow slices,
 * each cut across where the front arm's art can begin (its back edge,
 * raised by the art's height), and each part sorts by the front edge of its
 * own arm.
 */
export function placeBuilding(
    footprint: Footprint,
    imageHeight: number,
    originX: number,
): Placement {
    const { x, y, wide, deep } = footprint;
    const width = (wide + deep) * HALF_W;
    // The footprint's bottom corner, the grid point (x + wide, y + deep).
    const bottomX = originX + (x + wide - (y + deep)) * HALF_W;
    const bottomY = (x + wide + y + deep) * HALF_H;
    const left = bottomX - wide * HALF_W;
    // World y of the back corner; image y is world y less the image's top.
    const backY = (x + y) * HALF_H;
    const top = bottomY - imageHeight;
    const strips: Strip[] = [];

    // A tile's back and front edges at u pixels right of the back corner: a
    // tile n rows down (n = dx + dy) whose dx − dy is k has its top corner at
    // u = k × 32 and its edges rise half a pixel per pixel away from it.
    const edge = ({ n, k }: { n: number; k: number }, u: number) => ({
        back: backY + n * HALF_H + Math.abs(u - k * HALF_W) / 2,
        front: backY + (n + 2) * HALF_H - Math.abs(u - k * HALF_W) / 2,
    });
    const artHeight = imageHeight - (wide + deep) * HALF_H;

    for (let i = 0; i < wide + deep; i++) {
        // Going down the strip, tile by tile: the tile n rows down is the one
        // of the two diagonals under the strip whose dx − dy has n's parity.
        type Run = {
            first: { n: number; k: number };
            last: { n: number; k: number };
        };
        const runs: Run[] = [];
        let open = false;

        for (let n = 0; n < wide + deep - 1; n++) {
            const k = i - deep + ((((n - i + deep) % 2) + 2) % 2);
            const dx = (n + k) / 2;
            const dy = (n - k) / 2;
            const filled =
                dx >= 0 &&
                dx < wide &&
                dy >= 0 &&
                dy < deep &&
                covers(footprint, [x + dx, y + dy]);

            if (filled && open) runs[runs.length - 1].last = { n, k };
            if (filled && !open) runs.push({ first: { n, k }, last: { n, k } });

            open = filled;
        }

        // A strip with a gap is cut along the front part's top edge, which
        // slopes; in narrower slices the straight cuts follow it closely.
        const slices = runs.length > 1 ? GAP_SLICES : 1;
        const sliceWidth = HALF_W / slices;

        for (let slice = 0; slice < slices; slice++) {
            const u = (i - deep) * HALF_W + (slice + 0.5) * sliceWidth;
            let from = 0;

            runs.forEach((run, r) => {
                const to =
                    r === runs.length - 1
                        ? imageHeight
                        : Math.min(
                              imageHeight,
                              Math.max(
                                  from,
                                  edge(runs[r + 1].first, u).back -
                                      artHeight -
                                      top,
                              ),
                          );

                strips.push({
                    offsetX: i * HALF_W + slice * sliceWidth,
                    offsetY: from,
                    width: sliceWidth,
                    height: to - from,
                    depthY: edge(run.last, u).front,
                });
                from = to;
            });
        }
    }

    return { left, top, width, height: imageHeight, strips };
}

/**
 * Whether any strip of the building is drawn over a box (such as an avatar's
 * sprite) sorted at `depthY`.
 */
export function hides(
    placement: Placement,
    box: { left: number; right: number; top: number; bottom: number },
    depthY: number,
): boolean {
    if (
        box.bottom <= placement.top ||
        box.top >= placement.top + placement.height
    ) {
        return false;
    }

    return placement.strips.some(
        (strip) =>
            strip.depthY > depthY &&
            box.right > placement.left + strip.offsetX &&
            box.left < placement.left + strip.offsetX + strip.width &&
            box.bottom > placement.top + strip.offsetY &&
            box.top < placement.top + strip.offsetY + strip.height,
    );
}

/**
 * Whether a building should fade to show the player behind it. The door is
 * in the footprint's front row, so a player stepping into it is drawn among
 * the walls beside it; that is walking in, not being hidden, so anywhere
 * within a tile of any of the door's tiles never fades the building.
 */
export function hidesPlayer(
    placement: Placement,
    door: Footprint,
    player: {
        /** The player's (possibly fractional) tile position. */
        at: { x: number; y: number };
        box: { left: number; right: number; top: number; bottom: number };
        depthY: number;
    },
): boolean {
    const outside = (at: number, from: number, length: number) =>
        Math.max(0, from - at, at - (from + length - 1));
    const inDoorway =
        Math.max(
            outside(player.at.x, door.x, door.wide),
            outside(player.at.y, door.y, door.deep),
        ) < 1;

    return !inDoorway && hides(placement, player.box, player.depthY);
}
