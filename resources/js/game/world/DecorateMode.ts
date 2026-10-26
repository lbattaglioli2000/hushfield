import Phaser from 'phaser';
import type { ArmedPiece } from '../bus';
import type { PlacedFurniture, Tile } from '../protocol/types';
import { covers, tilesOf } from '../shared/footprint';
import { TILE_HEIGHT } from '../shared/iso';
import {
    blocksWalking,
    FACINGS,
    footprintOf,
    pieceAt,
} from '../shared/furniture';
import { DEPTH, FURNITURE } from '../theme';
import type { IsoMap } from './IsoMap';

/** The requests decorating makes, each for one owned piece; the server says whether it fits. */
export type DecoratePort = {
    placeFurniture(piece: number, tile: Tile, rotation?: number): Promise<void>;
    putAwayFurniture(piece: number): Promise<void>;
};

/**
 * Decorate mode in the player's own home: while it is on, taps place the
 * armed piece or put away the one tapped, and an outline shows where the
 * armed piece would go. Honoured only in the player's own home.
 */
export class DecorateMode {
    private active = false;
    private armed: ArmedPiece | null = null;
    private ownHome = false;
    /** The current home's arrangement, as the server last said. */
    private placed: PlacedFurniture[] = [];
    /** While decorating, the outline of where the armed piece would go. */
    private ghost: Phaser.GameObjects.Graphics | null = null;

    constructor(
        private readonly port: DecoratePort,
        private readonly refused: (error: unknown) => void,
    ) {}

    /** Whether taps and keys decorate rather than walk. */
    get on(): boolean {
        return this.active && this.ownHome;
    }

    /** Called once the scene can draw. */
    create(scene: Phaser.Scene): void {
        this.ghost = scene.add.graphics().setDepth(DEPTH.hover);
    }

    /** UI → game: switch decorate mode, with the piece to place (if any). */
    set(active: boolean, armed: ArmedPiece | null): void {
        this.active = active;
        this.armed = active ? armed : null;

        if (!this.armed) this.ghost?.clear();
    }

    /** After every snapshot: whose home this is, and how it is arranged. */
    enter(ownHome: boolean): void {
        this.ownHome = ownHome;

        if (!ownHome) this.set(false, null);
    }

    arrange(placed: PlacedFurniture[]): void {
        this.placed = placed;
    }

    /** UI → game: a placed piece turns a quarter turn where it stands. */
    turn(id: number): void {
        const piece = this.placed.find((p) => p.piece === id);

        if (!piece || !this.ownHome) return;

        this.port
            .placeFurniture(
                id,
                [piece.x, piece.y],
                (piece.rotation + 1) % FACINGS,
            )
            .catch(this.refused);
    }

    /**
     * A tap while decorating: put away the piece on the tile, or place the armed
     * one with its back corner there. A rug under the tap takes the armed piece on top.
     */
    tap(tile: Tile | null): void {
        if (!tile) return;

        const placed = pieceAt(this.placed, tile);
        const armed = this.armed;
        const request =
            placed && (blocksWalking(placed) || !armed)
                ? this.port.putAwayFurniture(placed.piece)
                : armed
                  ? this.port.placeFurniture(armed.piece, tile)
                  : null;

        request?.catch(this.refused);
    }

    /** Outlines the tiles the armed piece would cover with its back corner on the hovered tile. */
    hover(map: IsoMap | null, tile: Tile | null): void {
        const ghost = this.ghost;

        if (!ghost) return;

        ghost.clear();

        const armed = this.on ? this.armed : null;

        if (!tile || !armed || !map) return;

        const rotation =
            this.placed.find((p) => p.piece === armed.piece)?.rotation ?? 0;
        const footprint = footprintOf(
            armed.item.furniture ?? { wide: 1, deep: 1 },
            tile[0],
            tile[1],
            rotation,
        );
        // A corner of a tile on the ground.
        const corner = (gx: number, gy: number) => {
            const centre = map.toWorld(gx, gy);

            return new Phaser.Math.Vector2(
                centre.x,
                centre.y - TILE_HEIGHT / 2,
            );
        };

        ghost.fillStyle(FURNITURE.ghostColor, 0.18);
        ghost.lineStyle(2, FURNITURE.ghostColor, 0.9);

        // Each covered tile, outlined only where the shape ends.
        for (const [x, y] of tilesOf(footprint)) {
            ghost.fillPoints(
                [
                    corner(x, y),
                    corner(x + 1, y),
                    corner(x + 1, y + 1),
                    corner(x, y + 1),
                ],
                true,
            );

            const edges: Array<[Tile, [number, number], [number, number]]> = [
                [
                    [x, y - 1],
                    [x, y],
                    [x + 1, y],
                ],
                [
                    [x + 1, y],
                    [x + 1, y],
                    [x + 1, y + 1],
                ],
                [
                    [x, y + 1],
                    [x, y + 1],
                    [x + 1, y + 1],
                ],
                [
                    [x - 1, y],
                    [x, y],
                    [x, y + 1],
                ],
            ];

            for (const [beside, [ax, ay], [bx, by]] of edges) {
                if (covers(footprint, beside)) continue;

                const a = corner(ax, ay);
                const b = corner(bx, by);
                ghost.lineBetween(a.x, a.y, b.x, b.y);
            }
        }
    }
}
