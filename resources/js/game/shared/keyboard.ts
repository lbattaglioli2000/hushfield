import type { Tile } from '../protocol/types';
import type { Grid } from './astar';
import { isWalkable } from './astar';

/**
 * Keyboard walking in screen directions. The iso projection puts screen x on
 * (x − y) and screen y on (x + y), so one screen direction is a tile diagonal.
 */
const SCREEN: Record<string, Tile> = {
    up: [-1, -1],
    down: [1, 1],
    left: [-1, 1],
    right: [1, -1],
};

/** KeyboardEvent.code → screen direction. Codes, so WASD stays put on other layouts. */
export const MOVEMENT_KEYS: Record<string, keyof typeof SCREEN> = {
    KeyW: 'up',
    ArrowUp: 'up',
    KeyS: 'down',
    ArrowDown: 'down',
    KeyA: 'left',
    ArrowLeft: 'left',
    KeyD: 'right',
    ArrowRight: 'right',
};

/**
 * The tile step for the held keys, or null when nothing (or only opposite keys)
 * is held. Two keys combine: up + right = (0, −1).
 */
export function directionOf(held: Iterable<string>): Tile | null {
    const screens = new Set<string>();

    for (const code of held) {
        const screen = MOVEMENT_KEYS[code];

        if (screen) screens.add(screen);
    }

    let x = 0;
    let y = 0;

    for (const screen of screens) {
        x += SCREEN[screen][0];
        y += SCREEN[screen][1];
    }

    if (x === 0 && y === 0) return null;

    return [Math.sign(x), Math.sign(y)];
}

/**
 * Walks from start in a straight line until the next step is blocked or
 * maxSteps is reached. Diagonal steps follow findPath's no-corner-cutting rule.
 * Returns the tiles including start; just [start] when the first step is blocked.
 */
export function straightLine(
    grid: Grid,
    start: Tile,
    direction: Tile,
    maxSteps: number,
): Tile[] {
    const [dx, dy] = direction;
    const line: Tile[] = [start];
    let [x, y] = start;

    for (let i = 0; i < maxSteps; i++) {
        const nx = x + dx;
        const ny = y + dy;

        if (!isWalkable(grid, nx, ny)) break;

        if (
            dx !== 0 &&
            dy !== 0 &&
            (!isWalkable(grid, nx, y) || !isWalkable(grid, x, ny))
        ) {
            break;
        }

        x = nx;
        y = ny;
        line.push([x, y]);
    }

    return line;
}
