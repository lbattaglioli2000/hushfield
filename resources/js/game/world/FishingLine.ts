import Phaser from 'phaser';
import type { FishingPhase } from '../bus';
import type { Tile } from '../protocol/types';
import { distanceTo } from '../shared/footprint';
import { DEPTH, FISHING } from '../theme';
import type { IsoMap } from './IsoMap';

type Point = { x: number; y: number };

/**
 * The rod, line and bobber, drawn as plain shapes until there is art: a short
 * stick from the avatar, a sagging line, and a bobber that bobs on the water,
 * dips and ripples when something bites, and comes back (with something on
 * it) or goes slack when the line is reeled in.
 */
export class FishingLine {
    private readonly graphics: Phaser.GameObjects.Graphics;
    private phase: FishingPhase = 'idle';
    /** Where the bobber floats, in world pixels; null while no line is out. */
    private water: Point | null = null;
    /** When the phase began, in scene time. */
    private since = 0;
    /** After a reel: whether something came up, and when the reel finished. */
    private ending: { landed: boolean; at: number } | null = null;

    constructor(scene: Phaser.Scene) {
        this.graphics = scene.add.graphics();
    }

    get active(): boolean {
        return this.water !== null && this.ending === null;
    }

    /** Casting puts the bobber at `water`; every later phase keeps it there. */
    set(
        phase: FishingPhase,
        now: number,
        water: Point | null,
        landed?: boolean,
    ): void {
        if (phase === 'idle') {
            this.ending =
                this.water && landed !== undefined ? { landed, at: now } : null;

            if (!this.ending) this.water = null;
        } else {
            if (phase === 'casting' || !this.water) this.water = water;
            this.ending = null;
        }

        this.phase = phase;
        this.since = now;
    }

    reset(): void {
        this.water = null;
        this.ending = null;
        this.phase = 'idle';
        this.graphics.clear();
    }

    /** Redraws for this frame. `feet` is where the fisher stands. */
    update(now: number, feet: Point | null): void {
        const g = this.graphics.clear();

        if (!this.water || !feet) return;

        const water = this.water;
        const toward = Math.sign(water.x - feet.x) || 1;
        const hand = { x: feet.x + toward * 6, y: feet.y - FISHING.handY };
        const tip = {
            x: hand.x + toward * FISHING.rodLength,
            y: hand.y - FISHING.rodLength * 0.8,
        };
        const t = now - this.since;
        g.setDepth(DEPTH.avatars + Math.max(feet.y, water.y) + 1);

        if (this.ending) {
            this.drawEnding(
                g,
                now - this.ending.at,
                this.ending.landed,
                hand,
                tip,
                water,
            );

            return;
        }

        const biting = this.phase === 'bite';
        const bob = biting
            ? FISHING.biteDip + Math.sin(t / 45) * 2
            : Math.sin(t / 420) * 1.5;
        const bobber = { x: water.x, y: water.y + bob };

        this.drawRod(g, hand, tip, 1);
        this.drawLine(
            g,
            tip,
            bobber,
            biting || this.phase === 'reeling' ? 4 : 18,
            0.85,
        );
        this.drawRipples(g, water, t, biting);
        this.drawBobber(g, bobber, 1);
    }

    destroy(): void {
        this.graphics.destroy();
    }

    private drawEnding(
        g: Phaser.GameObjects.Graphics,
        elapsed: number,
        landed: boolean,
        hand: Point,
        tip: Point,
        water: Point,
    ): void {
        const progress = Math.min(1, elapsed / FISHING.endMs);

        if (progress >= 1) {
            this.water = null;
            this.ending = null;

            return;
        }

        const fade = 1 - progress;
        this.drawRod(g, hand, tip, fade);

        if (landed) {
            // The bobber comes up out of the water with the catch hanging below it.
            const lift = Phaser.Math.Easing.Cubic.Out(progress);
            const bobber = {
                x: Phaser.Math.Linear(water.x, tip.x, lift),
                y:
                    Phaser.Math.Linear(water.y, tip.y + 14, lift) -
                    Math.sin(lift * Math.PI) * 30,
            };
            this.drawLine(g, tip, bobber, 0, fade);
            this.drawBobber(g, bobber, fade);
            g.fillStyle(FISHING.catchColor, fade).fillEllipse(
                bobber.x,
                bobber.y + 10,
                8,
                14,
            );
            this.drawRing(
                g,
                water,
                6 + progress * 22,
                FISHING.rippleColor,
                fade * 0.8,
            );
        } else {
            // Slack line, an empty hook, and the water closing over where it was.
            this.drawLine(g, tip, water, 30 * (1 + progress), fade * 0.6);
            this.drawRing(
                g,
                water,
                4 + progress * 10,
                FISHING.lineColor,
                fade * 0.5,
            );
        }
    }

    private drawRod(
        g: Phaser.GameObjects.Graphics,
        hand: Point,
        tip: Point,
        alpha: number,
    ): void {
        g.lineStyle(2.5, FISHING.rodColor, alpha).lineBetween(
            hand.x,
            hand.y,
            tip.x,
            tip.y,
        );
    }

    private drawLine(
        g: Phaser.GameObjects.Graphics,
        from: Point,
        to: Point,
        sag: number,
        alpha: number,
    ): void {
        const curve = new Phaser.Curves.QuadraticBezier(
            new Phaser.Math.Vector2(from.x, from.y),
            new Phaser.Math.Vector2(
                (from.x + to.x) / 2,
                Math.max(from.y, to.y) + sag,
            ),
            new Phaser.Math.Vector2(to.x, to.y),
        );
        g.lineStyle(1, FISHING.lineColor, alpha).strokePoints(
            curve.getPoints(16),
        );
    }

    private drawBobber(
        g: Phaser.GameObjects.Graphics,
        at: Point,
        alpha: number,
    ): void {
        g.fillStyle(FISHING.bobberColor, alpha).fillCircle(at.x, at.y, 4);
        g.fillStyle(FISHING.lineColor, alpha).fillCircle(at.x, at.y - 3, 2);
    }

    /** Rings spreading on the water: slow and faint while waiting, quick and bright while biting. */
    private drawRipples(
        g: Phaser.GameObjects.Graphics,
        at: Point,
        t: number,
        biting: boolean,
    ): void {
        const period = biting ? 280 : 1600;
        const phase = (t % period) / period;
        const reach = biting ? 26 : 12;
        const color = biting ? FISHING.biteColor : FISHING.rippleColor;
        this.drawRing(
            g,
            at,
            4 + phase * reach,
            color,
            (1 - phase) * (biting ? 0.9 : 0.4),
        );
    }

    /** A flattened ring, so it lies on the isometric water. */
    private drawRing(
        g: Phaser.GameObjects.Graphics,
        at: Point,
        radius: number,
        color: number,
        alpha: number,
    ): void {
        g.lineStyle(1.5, color, alpha).strokeEllipse(
            at.x,
            at.y + 2,
            radius * 2,
            radius,
        );
    }
}

/**
 * Where the bobber lands for a player at `at`: out over the water from the
 * fishing spot nearest them, past the spot's first unwalkable neighbouring
 * tile, preferring the far side.
 */
export function waterBeside(map: IsoMap, at: Tile): { x: number; y: number } {
    const spot = map.fixtures
        .filter((fixture) => fixture.kind === 'fishing')
        .sort((a, b) => distanceTo(a, at) - distanceTo(b, at))[0];
    const [x, y] = spot?.tile ?? at;
    const [dx, dy] = (
        [
            [0, 1],
            [1, 0],
            [-1, 0],
            [0, -1],
        ] as const
    ).find(([dx, dy]) => !map.isWalkable([x + dx, y + dy])) ?? [0, 1];

    return map.toWorld(
        x + dx * FISHING.reachTiles,
        y + dy * FISHING.reachTiles,
    );
}
