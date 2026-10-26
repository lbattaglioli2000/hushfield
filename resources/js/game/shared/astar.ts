import type { Tile } from '../protocol/types';

/**
 * Client-side prediction only; the server's path always wins. Mirrors
 * app/Domain/World/Pathfinder.php: 8 directions, no diagonal corner-cutting,
 * same neighbour order and tie-breaking.
 */
export type Grid = { width: number; height: number; walkable: Uint8Array };

const DIRECTIONS: Tile[] = [
    [0, -1],
    [1, 0],
    [0, 1],
    [-1, 0],
    [1, -1],
    [1, 1],
    [-1, 1],
    [-1, -1],
];

export function isWalkable(grid: Grid, x: number, y: number): boolean {
    return (
        x >= 0 &&
        y >= 0 &&
        x < grid.width &&
        y < grid.height &&
        grid.walkable[y * grid.width + x] === 1
    );
}

function heuristic(x: number, y: number, gx: number, gy: number): number {
    const dx = Math.abs(x - gx);
    const dy = Math.abs(y - gy);

    return dx + dy + (Math.SQRT2 - 2) * Math.min(dx, dy);
}

type Node = { key: number; f: number; h: number; seq: number };

function before(a: Node, b: Node): boolean {
    return a.f !== b.f ? a.f < b.f : a.h !== b.h ? a.h < b.h : a.seq < b.seq;
}

/** Small binary heap; the open set rarely exceeds a few hundred nodes. */
class Heap {
    private items: Node[] = [];

    get size(): number {
        return this.items.length;
    }

    push(node: Node): void {
        const items = this.items;
        items.push(node);
        let i = items.length - 1;

        while (i > 0) {
            const parent = (i - 1) >> 1;

            if (!before(items[i], items[parent])) break;
            [items[i], items[parent]] = [items[parent], items[i]];
            i = parent;
        }
    }

    pop(): Node {
        const items = this.items;
        const top = items[0];
        const end = items.pop() as Node;

        if (items.length > 0) {
            items[0] = end;
            let i = 0;

            for (;;) {
                const l = i * 2 + 1;
                const r = l + 1;
                let best = i;

                if (l < items.length && before(items[l], items[best])) best = l;
                if (r < items.length && before(items[r], items[best])) best = r;
                if (best === i) break;
                [items[i], items[best]] = [items[best], items[i]];
                i = best;
            }
        }

        return top;
    }
}

/** A blocked start is fine: someone furniture was put down on can still walk off it. */
export function findPath(
    grid: Grid,
    start: Tile,
    goal: Tile,
    maxSteps: number,
): Tile[] | null {
    const [gx, gy] = goal;

    if (
        !isWalkable(grid, gx, gy) ||
        start[0] < 0 ||
        start[1] < 0 ||
        start[0] >= grid.width ||
        start[1] >= grid.height
    ) {
        return null;
    }

    if (start[0] === gx && start[1] === gy) return [start];

    const width = grid.width;
    const startKey = start[1] * width + start[0];
    const goalKey = gy * width + gx;
    const cost = new Map<number, number>([[startKey, 0]]);
    const cameFrom = new Map<number, number>();
    const closed = new Set<number>();
    const open = new Heap();
    let seq = 0;
    const h0 = heuristic(start[0], start[1], gx, gy);
    open.push({ key: startKey, f: h0, h: h0, seq: seq++ });

    while (open.size > 0) {
        const current = open.pop().key;

        if (closed.has(current)) continue;

        if (current === goalKey) {
            const path: Tile[] = [
                [current % width, Math.floor(current / width)],
            ];
            let k = current;

            while (cameFrom.has(k)) {
                k = cameFrom.get(k) as number;
                path.push([k % width, Math.floor(k / width)]);

                if (path.length - 1 > maxSteps) return null;
            }

            return path.reverse();
        }

        closed.add(current);
        const x = current % width;
        const y = Math.floor(current / width);

        for (const [dx, dy] of DIRECTIONS) {
            const nx = x + dx;
            const ny = y + dy;

            if (!isWalkable(grid, nx, ny)) continue;

            const diagonal = dx !== 0 && dy !== 0;

            if (
                diagonal &&
                (!isWalkable(grid, x + dx, y) || !isWalkable(grid, x, y + dy))
            ) {
                continue;
            }

            const next = ny * width + nx;

            if (closed.has(next)) continue;

            const tentative =
                (cost.get(current) as number) + (diagonal ? Math.SQRT2 : 1);
            const known = cost.get(next);

            if (known === undefined || tentative < known) {
                cost.set(next, tentative);
                cameFrom.set(next, current);
                const h = heuristic(nx, ny, gx, gy);
                open.push({ key: next, f: tentative + h, h, seq: seq++ });
            }
        }
    }

    return null;
}
