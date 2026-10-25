import type { PathState, Tile } from '../protocol/types';
import type { Grid } from '../shared/astar';
import { findPath } from '../shared/astar';
import { positionOf } from '../shared/pathClock';
import { RefusedError } from '../net/http';
import type { AvatarView } from './AvatarView';

/** The slice of the network layer movement needs. */
export type MovementPort = {
    serverNow(): number;
    move(tile: Tile): Promise<PathState | null>;
};

export type MovementDeps = {
    port: MovementPort;
    maxPathSteps: number;
    /** The player's own avatar, looked up fresh: a resync can replace it mid-request. */
    self: () => AvatarView | undefined;
    grid: () => Grid | null;
    showMarker: (tile: Tile) => void;
    hideMarker: () => void;
    notice: (message: string) => void;
};

/**
 * Moves the player's own avatar: predicts the path locally so it starts walking
 * at once, then reconciles with the server's answer.
 */
export class MovementController {
    private seq = 0;
    /** The last path the server accepted, to fall back to when a move is dropped or refused. */
    private confirmed: PathState | null = null;

    constructor(private readonly deps: MovementDeps) {}

    /** Records a path the server sent for this player (snapshot or join). */
    confirm(state: PathState): void {
        this.confirmed = state;
    }

    /**
     * Where the player's own avatar is headed: `next` is the tile a new path starts
     * from (the end of the step under way, as in predict()), `end` the last tile of
     * its current path. Null before the avatar exists.
     */
    heading(): { next: Tile; end: Tile; moving: boolean } | null {
        const avatar = this.deps.self();

        if (!avatar) return null;

        const pos = positionOf(avatar.state, this.deps.port.serverNow());
        const midStep = !pos.finished && pos.progress > 0;
        const path = avatar.state.path;

        return {
            next: midStep ? pos.to : pos.tile,
            end: path[path.length - 1],
            moving: !pos.finished,
        };
    }

    async walkTo(target: Tile): Promise<void> {
        const { port, self } = this.deps;
        const avatar = self();

        if (!avatar || !this.deps.grid()) return;

        const seq = ++this.seq;
        const predicted = this.predict(avatar.state, target);

        if (predicted) {
            avatar.setState(predicted, port.serverNow());
            this.deps.showMarker(target);
        }

        let confirmed: PathState | null = null;
        let refusal: unknown = null;

        try {
            confirmed = await port.move(target);
        } catch (error) {
            refusal = error;
        }

        // A later tap owns the avatar now; this response is stale.
        if (seq !== this.seq) return;

        const current = self();

        if (!current) return;

        if (confirmed) {
            // Same tiles as predicted: keep the predicted timing. It runs one-way latency ahead
            // of what others see, which is the usual tradeoff; adopting the server's timing
            // would pull the avatar back by latency × speed.
            if (!predicted || !samePath(predicted.path, confirmed.path)) {
                current.setState(confirmed, port.serverNow());
            }

            this.confirmed = current.state;

            return;
        }

        // Dropped (rate-limited) or refused: go back to what the server last confirmed.
        this.deps.hideMarker();

        if (this.confirmed) current.setState(this.confirmed, port.serverNow());

        if (
            refusal instanceof RefusedError &&
            refusal.reason === 'unreachable'
        ) {
            this.deps.notice(refusal.message);
        }
    }

    /** Same rule as MovePlayer: finish the step under way, then follow A* from its end. */
    private predict(current: PathState, target: Tile): PathState | null {
        const grid = this.deps.grid();

        if (!grid) return null;

        const now = this.deps.port.serverNow();
        const pos = positionOf(current, now);
        const midStep = !pos.finished && pos.progress > 0;
        const start = midStep ? pos.to : pos.tile;
        const route = findPath(grid, start, target, this.deps.maxPathSteps);

        if (!route) return null;

        return {
            path: midStep ? [pos.from, ...route] : route,
            started_at_ms: midStep
                ? now - Math.round(pos.segmentElapsedMs)
                : now,
            speed: current.speed,
        };
    }
}

function samePath(a: Tile[], b: Tile[]): boolean {
    return (
        a.length === b.length &&
        a.every((tile, i) => tile[0] === b[i][0] && tile[1] === b[i][1])
    );
}
