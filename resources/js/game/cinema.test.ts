import { describe, expect, it } from 'vite-plus/test';
import { correction, showing } from './cinema';
import type { Screening } from './protocol/types';

const film = (id: number, starts: number, ends: number): Screening => ({
    id,
    video_id: `video${id}`.padEnd(11, 'x'),
    title: `Film ${id}`,
    starts_at_ms: starts,
    ends_at_ms: ends,
});

describe('showing', () => {
    // A film stopped early ends where the next one starts; an intermission follows the second.
    const programme = [
        film(1, 0, 1000),
        film(2, 1000, 5000),
        film(3, 6000, 9000),
    ];

    it.each([
        [0, 1, 2],
        [999, 1, 2],
        // The first frame of a film belongs to it, the last millisecond of the one before does not.
        [1000, 2, 3],
        // The intermission: nothing on, the next film coming.
        [5000, null, 3],
        [5999, null, 3],
        [6000, 3, null],
        [9000, null, null],
    ] as const)('at %i ms film %s is on and %s is next', (now, on, next) => {
        const result = showing(programme, now);

        expect(result.film?.id ?? null).toBe(on);
        expect(result.next?.id ?? null).toBe(next);
    });
});

describe('correction', () => {
    it.each([
        [0, 'hold'],
        [0.4, 'hold'],
        [-0.4, 'hold'],
        // Ahead of the room plays slower, behind plays faster.
        [0.5, 'slower'],
        [-0.5, 'faster'],
        [3, 'slower'],
        [-3, 'faster'],
        [3.01, 'seek'],
        [-12, 'seek'],
    ] as const)('a player %f s off the room should %s', (drift, action) => {
        expect(correction(drift)).toBe(action);
    });
});
