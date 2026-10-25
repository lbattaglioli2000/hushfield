import type Phaser from 'phaser';
import type { Tile } from '../protocol/types';
import type { Grid } from '../shared/astar';
import { isWalkable } from '../shared/astar';
import {
    screenToTile,
    TILE_HEIGHT,
    TILE_WIDTH,
    tileToScreen,
} from '../shared/iso';
import { CAMERA, DEPTH } from '../theme';

/** Names inside the Tiled map and the texture key its tileset is drawn from. */
export const MAP_LAYERS = {
    tileset: 'placeholder',
    ground: 'ground',
    walkable: 'walkable',
} as const;
export const TILES_TEXTURE = 'tiles';

/** One room's isometric tilemap: the drawn ground layer, the walkable grid, and tile↔world conversion. */
export class IsoMap {
    readonly key: string;
    readonly grid: Grid;
    /** World x of tile (0,0)'s top corner. */
    readonly originX: number;
    private readonly layer: Phaser.Tilemaps.TilemapLayer | null = null;

    constructor(scene: Phaser.Scene, key: string) {
        const map = scene.make.tilemap({ key });
        const tileset = map.addTilesetImage(MAP_LAYERS.tileset, TILES_TEXTURE);
        this.key = key;
        this.originX = map.height * (TILE_WIDTH / 2);

        if (tileset) {
            // Phaser draws an isometric tile with its image's top-left at the returned
            // world point, so shift by half a tile to put tile (0,0)'s top corner at originX.
            this.layer = map.createLayer(
                MAP_LAYERS.ground,
                tileset,
                this.originX - TILE_WIDTH / 2,
                0,
            );
            this.layer?.setDepth(DEPTH.ground);
        }

        const walkable = map.getLayer(MAP_LAYERS.walkable);
        const cells = new Uint8Array(map.width * map.height);

        walkable?.data.forEach((row, y) =>
            row.forEach((tile, x) => {
                cells[y * map.width + x] = tile.index > 0 ? 1 : 0;
            }),
        );

        this.grid = { width: map.width, height: map.height, walkable: cells };

        const widthPx = (map.width + map.height) * (TILE_WIDTH / 2);
        const heightPx = (map.width + map.height) * (TILE_HEIGHT / 2);
        const pad = CAMERA.boundsPadding;
        scene.cameras.main.setBounds(
            -pad,
            -pad,
            widthPx + pad * 2,
            heightPx + pad * 2,
        );
    }

    isWalkable(tile: Tile): boolean {
        return isWalkable(this.grid, tile[0], tile[1]);
    }

    /** Centre of a (possibly fractional) tile in world pixels. */
    toWorld(x: number, y: number): { x: number; y: number } {
        return tileToScreen(x, y, this.originX);
    }

    /** The tile under a world point, or null outside the map. */
    tileAt(worldX: number, worldY: number): Tile | null {
        const tile = screenToTile(worldX, worldY, this.originX);

        return tile[0] >= 0 &&
            tile[1] >= 0 &&
            tile[0] < this.grid.width &&
            tile[1] < this.grid.height
            ? tile
            : null;
    }

    destroy(): void {
        this.layer?.destroy();
    }
}
