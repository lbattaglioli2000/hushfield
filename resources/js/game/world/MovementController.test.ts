import { describe, expect, it } from 'vite-plus/test';
import type { PathState, Tile } from '../protocol/types';
import type { Grid } from '../shared/astar';
import type { AvatarView } from './AvatarView';
import { MovementController } from './MovementController';

const DOOR: Tile = [2, 0];

/** One open row, three tiles long; four tiles a second, so a tile takes 250 ms. */
function setup() {
    const grid: Grid = {
        width: 3,
        height: 1,
        walkable: new Uint8Array(3).fill(1),
    };
    const standing: PathState = { path: [[0, 0]], started_at_ms: 0, speed: 4 };
    const clock = { now: 1000 };
    const avatar = {
        state: standing,
        setState(state: PathState) {
            this.state = state;
        },
    };
    let answer: (state: PathState | null) => void = () => {};

    const movement = new MovementController({
        port: {
            serverNow: () => clock.now,
            move: () => new Promise((resolve) => (answer = resolve)),
        },
        maxPathSteps: 10,
        self: () => avatar as unknown as AvatarView,
        grid: () => grid,
        showMarker: () => {},
        hideMarker: () => {},
        notice: () => {},
    });
    movement.confirm(standing);

    return {
        movement,
        clock,
        answer: (state: PathState | null) => answer(state),
    };
}

describe('MovementController.arrivedAt', () => {
    it('waits for the server to accept the walk and finish it by its own clock', async () => {
        const { movement, clock, answer } = setup();

        // Predicted from 1000, so the avatar reaches the door at 1500.
        const walking = movement.walkTo(DOOR);
        clock.now = 1600;

        // The move is still in flight: the server has the player standing at (0, 0).
        expect(movement.arrivedAt(DOOR)).toBeNull();

        // It reached the server 300 ms after the tap, so the server's walk ends at 1800.
        const accepted: PathState = {
            path: [[0, 0], [1, 0], DOOR],
            started_at_ms: 1300,
            speed: 4,
        };
        answer(accepted);
        await walking;

        expect(movement.arrivedAt(DOOR)).toBeNull();

        clock.now = 1800;
        expect(movement.arrivedAt(DOOR)).toBe(accepted);
    });

    it('never arrives on a walk the server dropped', async () => {
        const { movement, clock, answer } = setup();

        const walking = movement.walkTo(DOOR);
        answer(null);
        await walking;
        clock.now = 5000;

        expect(movement.arrivedAt(DOOR)).toBeNull();
    });
});
