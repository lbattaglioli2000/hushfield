import Phaser from 'phaser';
import type { PlacedFurniture, Tile } from '../protocol/types';
import { TILE_HEIGHT, TILE_WIDTH } from '../shared/iso';
import { DEPTH, FURNITURE } from '../theme';
import type { IsoMap } from './IsoMap';

/**
 * The furniture standing in a home. Each piece is drawn as a block tinted by
 * its item until items have artwork. Replaced wholesale on every change.
 */
export class FurnitureLayer {
    private pieces: Array<{
        placed: PlacedFurniture;
        view: Phaser.GameObjects.Graphics;
    }> = [];

    constructor(
        private readonly scene: Phaser.Scene,
        private readonly map: IsoMap,
    ) {}

    set(placed: PlacedFurniture[]): void {
        this.clear();
        this.pieces = placed.map((piece) => ({
            placed: piece,
            view: this.draw(piece),
        }));
    }

    /** The item standing on a tile, if any. */
    at(tile: Tile): PlacedFurniture | null {
        return (
            this.pieces.find(
                ({ placed }) => placed.x === tile[0] && placed.y === tile[1],
            )?.placed ?? null
        );
    }

    destroy(): void {
        this.clear();
    }

    private clear(): void {
        this.pieces.forEach(({ view }) => view.destroy());
        this.pieces = [];
    }

    private draw({ x, y, item }: PlacedFurniture): Phaser.GameObjects.Graphics {
        const centre = this.map.toWorld(x, y);
        const hw = (TILE_WIDTH / 2) * FURNITURE.footprint;
        const hh = (TILE_HEIGHT / 2) * FURNITURE.footprint;
        const lift = FURNITURE.heightPx;
        const top = colourFor(item.slug);
        const shaded = (factor: number) =>
            Phaser.Display.Color.GetColor(
                top.red * factor,
                top.green * factor,
                top.blue * factor,
            );
        const point = (dx: number, dy: number) =>
            new Phaser.Math.Vector2(centre.x + dx, centre.y + dy);

        return (
            this.scene.add
                .graphics()
                .fillStyle(shaded(FURNITURE.shade[0]))
                .fillPoints(
                    [
                        point(-hw, -lift),
                        point(0, hh - lift),
                        point(0, hh),
                        point(-hw, 0),
                    ],
                    true,
                )
                .fillStyle(shaded(FURNITURE.shade[1]))
                .fillPoints(
                    [
                        point(hw, -lift),
                        point(0, hh - lift),
                        point(0, hh),
                        point(hw, 0),
                    ],
                    true,
                )
                .fillStyle(top.color)
                .fillPoints(
                    [
                        point(0, -hh - lift),
                        point(hw, -lift),
                        point(0, hh - lift),
                        point(-hw, -lift),
                    ],
                    true,
                )
                // Just behind an avatar standing on the same tile.
                .setDepth(DEPTH.avatars + centre.y - 0.5)
        );
    }
}

/** A stable colour per item, so the same piece always looks the same. */
function colourFor(slug: string): Phaser.Display.Color {
    let hash = 0;

    for (const char of slug) hash = (hash * 31 + char.charCodeAt(0)) >>> 0;

    return Phaser.Display.Color.HSLToColor(
        (hash % 360) / 360,
        FURNITURE.saturation,
        FURNITURE.lightness,
    );
}
