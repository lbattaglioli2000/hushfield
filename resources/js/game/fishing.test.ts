import { describe, expect, it } from 'vite-plus/test';
import { linePhase } from './fishing';

describe('linePhase', () => {
    const cast = { id: 'c', bite_at_ms: 10_000, reel_by_ms: 11_500 };

    // The same bounds the server's Cast::judge() uses: the bite and the window's end both count.
    it.each([
        [9_999, 'waiting'],
        [10_000, 'bite'],
        [11_500, 'bite'],
        [11_501, 'gone'],
    ] as const)('at %i ms the line is %s', (now, phase) => {
        expect(linePhase(cast, now)).toBe(phase);
    });
});
