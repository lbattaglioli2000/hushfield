import { describe, expect, it } from 'vitest';
import { hearingVolume } from './voice';

describe('hearingVolume', () => {
    it('is full volume up close and silent at the edge of hearing', () => {
        expect(hearingVolume(0, 8)).toBe(1);
        expect(hearingVolume(2, 8)).toBe(1);
        expect(hearingVolume(8, 8)).toBe(0);
        expect(hearingVolume(20, 8)).toBe(0);
        expect(hearingVolume(Infinity, 8)).toBe(0);
    });

    it('fades faster than linearly in between', () => {
        // Halfway between full (2 tiles) and silence (8 tiles): a quarter, not a half.
        expect(hearingVolume(5, 8)).toBeCloseTo(0.25);
        expect(hearingVolume(6, 8)).toBeCloseTo(1 / 9);
        expect(hearingVolume(3, 8)).toBeGreaterThan(hearingVolume(4, 8));
    });
});
