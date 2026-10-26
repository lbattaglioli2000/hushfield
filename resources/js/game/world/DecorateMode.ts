import Phaser from 'phaser';
import type { Item, PlacedFurniture, Tile } from '../protocol/types';
import { TILE_HEIGHT } from '../shared/iso';
import {
    blocksWalking,
    FACINGS,
    footprintOf,
    pieceAt,
} from '../shared/furniture';
import { DEPTH, FURNITURE } from '../theme';
import type { IsoMap } from './IsoMap';

/** The requests decorating makes; the server says whether each piece fits. */
export type DecoratePort = {
    placeFurniture(
        itemId: number,
        tile: Tile,
        rotation?: number,
    ): Promise<void>;
    putAwayFurniture(itemId: number): Promise<void>;
};

/**
 * Decorate mode in the player's own home: while it is on, taps place the
 * armed piece or put away the one tapped, and an outline shows where the
 * armed piece would go. Honoured only in the player's own home.
 */
export class DecorateMode {
    private active = false;
    private armed: Item | null = null;
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
    set(active: boolean, armed: Item | null): void {
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
    turn(itemId: number): void {
        const piece = this.placed.find((p) => p.item.id === itemId);

        if (!piece || !this.ownHome) return;

        this.port
            .placeFurniture(
                itemId,
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
                ? this.port.putAwayFurniture(placed.item.id)
                : armed
                  ? this.port.placeFurniture(armed.id, tile)
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
            this.placed.find((p) => p.item.id === armed.id)?.rotation ?? 0;
        const { x, y, wide, deep } = footprintOf(
            armed.furniture ?? { wide: 1, deep: 1 },
            tile[0],
            tile[1],
            rotation,
        );
        // Corners of the footprint: its back, right, front and left, on the ground.
        const corner = (gx: number, gy: number) => {
            const centre = map.toWorld(gx, gy);

            return new Phaser.Math.Vector2(
                centre.x,
                centre.y - TILE_HEIGHT / 2,
            );
        };
        const outline = [
            corner(x, y),
            corner(x + wide, y),
            corner(x + wide, y + deep),
            corner(x, y + deep),
        ];

        ghost
            .fillStyle(FURNITURE.ghostColor, 0.18)
            .fillPoints(outline, true)
            .lineStyle(2, FURNITURE.ghostColor, 0.9)
            .strokePoints(outline, true);
    }
}
