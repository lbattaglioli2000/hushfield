import type { Tile } from './protocol';

/**
 * TypeScript twin of app/Domain/World/PathClock.php. Both are tested against
 * tests/fixtures/path-clock.json; change them together.
 */
export type PathPosition = {
    x: number;
    y: number;
    tile: Tile;
    offset: [number, number];
    segment: number;
    from: Tile;
    to: Tile;
    progress: number;
    segmentElapsedMs: number;
    finished: boolean;
};

export function stepLength(from: Tile, to: Tile): number {
    return from[0] !== to[0] && from[1] !== to[1] ? Math.SQRT2 : 1;
}

export function positionAt(
    path: Tile[],
    startedAtMs: number,
    speedTilesPerSec: number,
    nowMs: number,
): PathPosition {
    if (path.length === 0) {
        throw new Error('A path needs at least one tile.');
    }

    let remaining =
        (Math.max(0, nowMs - startedAtMs) * speedTilesPerSec) / 1000;
    const last = path.length - 1;

    for (let i = 0; i < last; i++) {
        const from = path[i];
        const to = path[i + 1];
        const length = stepLength(from, to);

        if (remaining < length) {
            const progress = remaining / length;
            const x = from[0] + (to[0] - from[0]) * progress;
            const y = from[1] + (to[1] - from[1]) * progress;
            const tile = progress < 0.5 ? from : to;

            return {
                x,
                y,
                tile,
                offset: [x - tile[0], y - tile[1]],
                segment: i,
                from,
                to,
                progress,
                segmentElapsedMs: (remaining / speedTilesPerSec) * 1000,
                finished: false,
            };
        }

        remaining -= length;
    }

    const end = path[last];

    return {
        x: end[0],
        y: end[1],
        tile: end,
        offset: [0, 0],
        segment: last,
        from: end,
        to: end,
        progress: 0,
        segmentElapsedMs: 0,
        finished: true,
    };
}
