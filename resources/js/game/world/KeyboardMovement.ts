import type { Tile } from '../protocol/types';
import type { Grid } from '../shared/astar';
import { directionOf, MOVEMENT_KEYS, straightLine } from '../shared/keyboard';
import type { MovementController } from './MovementController';

/** How far one held-key walk reaches before it is extended. */
const REACH_TILES = 8;
/** Extend the walk once this few tiles are left, so a held key keeps walking smoothly. */
const RETARGET_TILES = 2;
/** At most four keyboard moves a second, under the server's five-per-second limit. */
const MIN_INTERVAL_MS = 250;

export type KeyboardMovementDeps = {
    movement: MovementController;
    maxPathSteps: number;
    grid: () => Grid | null;
    /** True while keys must not move the avatar: travelling between rooms, or decorating. */
    blocked: () => boolean;
    activity: () => void;
};

/**
 * WASD and the arrow keys walk the player's own avatar in screen directions.
 * Sends a move when the held direction changes, when the walk nears its end,
 * and on release (stop at the end of the step under way); never one per tile.
 */
export class KeyboardMovement {
    private readonly held = new Set<string>();
    /** The direction the last move was sent for; null when not walking by keyboard. */
    private walking: Tile | null = null;
    private target: Tile | null = null;
    private lastSentAt = -Infinity;

    private readonly onKeyDown = (event: KeyboardEvent) => {
        if (!MOVEMENT_KEYS[event.code] || !fromGame(event)) return;
        if (event.ctrlKey || event.metaKey || event.altKey) return;

        // Arrow keys would otherwise scroll the page.
        event.preventDefault();

        if (this.deps.blocked()) return;

        this.held.add(event.code);
        this.deps.activity();
    };

    /** Releases count wherever focus is, so a key can't stay stuck. */
    private readonly onKeyUp = (event: KeyboardEvent) => {
        this.held.delete(event.code);
    };

    private readonly onBlur = () => this.held.clear();

    constructor(private readonly deps: KeyboardMovementDeps) {
        window.addEventListener('keydown', this.onKeyDown);
        window.addEventListener('keyup', this.onKeyUp);
        window.addEventListener('blur', this.onBlur);
    }

    destroy(): void {
        window.removeEventListener('keydown', this.onKeyDown);
        window.removeEventListener('keyup', this.onKeyUp);
        window.removeEventListener('blur', this.onBlur);
    }

    /** Called every frame with a monotonic clock in ms. */
    update(nowMs: number): void {
        if (this.deps.blocked()) {
            // A door or decorate mode takes over; the player presses again afterwards.
            this.held.clear();
            this.walking = null;
            this.target = null;

            return;
        }

        const direction = directionOf(this.held);

        if (!direction && !this.walking) return;

        const heading = this.deps.movement.heading();
        const grid = this.deps.grid();

        if (!heading || !grid) return;

        const changed =
            !direction || !this.walking || !sameTile(direction, this.walking);

        if (!changed && !this.runningOut(heading)) return;

        if (nowMs - this.lastSentAt < MIN_INTERVAL_MS) return;

        if (!direction) {
            // Released: stop at the end of the step under way.
            this.walking = null;
            this.target = null;

            if (!sameTile(heading.end, heading.next))
                this.send(heading.next, nowMs);

            return;
        }

        const line = straightLine(
            grid,
            heading.next,
            direction,
            Math.min(REACH_TILES, this.deps.maxPathSteps),
        );
        const target = line[line.length - 1];

        this.walking = direction;
        this.target = target;

        // Already headed there: a wall ahead, or a standing avatar facing one.
        if (sameTile(heading.end, target)) return;

        this.send(target, nowMs);
    }

    /** The walk nears its end, or the server dropped it and the avatar went elsewhere. */
    private runningOut(heading: { next: Tile; end: Tile }): boolean {
        if (!this.target || !sameTile(heading.end, this.target)) return true;

        const left = Math.max(
            Math.abs(heading.end[0] - heading.next[0]),
            Math.abs(heading.end[1] - heading.next[1]),
        );

        return left <= RETARGET_TILES;
    }

    private send(target: Tile, nowMs: number): void {
        this.lastSentAt = nowMs;
        this.deps.activity();
        void this.deps.movement.walkTo(target);
    }
}

/** Same rule as the chat bar's Enter: only keys aimed at the page or the game canvas. */
function fromGame(event: KeyboardEvent): boolean {
    return (
        event.target === document.body ||
        event.target instanceof HTMLCanvasElement
    );
}

function sameTile(a: Tile, b: Tile): boolean {
    return a[0] === b[0] && a[1] === b[1];
}
