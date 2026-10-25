import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vite-plus/test';
import type { Tile } from '../protocol/types';
import { positionAt } from './pathClock';

type Case = {
    name: string;
    path: Tile[];
    started_at_ms: number;
    speed: number;
    now_ms: number;
    expected: {
        tile: Tile;
        offset: [number, number];
        segment: number;
        finished: boolean;
    };
};

/** Shared with tests/Unit/World/PathClockTest.php: both twins must agree on every case. */
const cases = JSON.parse(
    readFileSync(
        new URL('../../../../tests/fixtures/path-clock.json', import.meta.url),
        'utf8',
    ),
) as Case[];

describe('positionAt', () => {
    it('has every case the PHP twin checks', () => {
        expect(cases).toHaveLength(15);
    });

    it.each(cases.map((c) => [c.name, c] as const))('%s', (_name, c) => {
        const position = positionAt(c.path, c.started_at_ms, c.speed, c.now_ms);
        const { expected } = c;

        expect(position.tile).toEqual(expected.tile);
        expect(position.offset[0]).toBeCloseTo(expected.offset[0], 9);
        expect(position.offset[1]).toBeCloseTo(expected.offset[1], 9);
        expect(position.segment).toBe(expected.segment);
        expect(position.finished).toBe(expected.finished);
        expect(position.x).toBeCloseTo(
            expected.tile[0] + expected.offset[0],
            9,
        );
        expect(position.y).toBeCloseTo(
            expected.tile[1] + expected.offset[1],
            9,
        );
    });

    it('refuses an empty path', () => {
        expect(() => positionAt([], 0, 4, 0)).toThrow();
    });
});
