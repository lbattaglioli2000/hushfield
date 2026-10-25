import Phaser from 'phaser';
import type { Tile } from '../protocol/types';
import type { Grid } from '../shared/astar';
import { isWalkable } from '../shared/astar';
import {
    screenToTile,
    TILE_FACE_TOP,
    TILE_HEIGHT,
    TILE_IMAGE_HEIGHT,
    TILE_WIDTH,
    tileToScreen,
} from '../shared/iso';
import { CAMERA, COLORS, DEPTH, DOOR, FONT_FAMILY } from '../theme';

/** Names inside the Tiled map and the texture key its tileset is drawn from. */
export const MAP_LAYERS = {
    tileset: 'placeholder',
    ground: 'ground',
    walkable: 'walkable',
    exits: 'exits',
    fixtures: 'fixtures',
} as const;
export const TILES_TEXTURE = 'tiles';

/** A door on the map. Walking onto its tile takes the player through it; the server checks. */
export type MapDoor = { name: string; tile: Tile; label: string | null };

/** Something on the map a player can use, such as the shop counter. Walking onto it or tapping it uses it. */
export type MapFixture = {
    name: string;
    tile: Tile;
    kind: string;
    label: string | null;
};

/** One room's isometric tilemap: the drawn ground layer and doors, the walkable grid, and tile↔world conversion. */
export class IsoMap {
    readonly key: string;
    readonly grid: Grid;
    /** World x of tile (0,0)'s top corner. */
    readonly originX: number;
    readonly doors: MapDoor[];
    readonly fixtures: MapFixture[];
    private readonly layer: Phaser.Tilemaps.TilemapLayer | null = null;
    private readonly decorations: Phaser.GameObjects.GameObject[] = [];

    constructor(scene: Phaser.Scene, key: string) {
        const map = scene.make.tilemap({ key });
        const tileset = map.addTilesetImage(
            MAP_LAYERS.tileset,
            TILES_TEXTURE,
            TILE_WIDTH,
            TILE_IMAGE_HEIGHT,
        );
        // Lift each block so its top face, not its image, lines up with the grid.
        tileset?.tileOffset.set(0, TILE_FACE_TOP);
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
        // Tiled positions objects on isometric maps in tile-height units on both axes.
        this.doors = (map.getObjectLayer(MAP_LAYERS.exits)?.objects ?? []).map(
            (object) => ({
                name: object.name,
                tile: [
                    Math.floor((object.x ?? 0) / TILE_HEIGHT),
                    Math.floor((object.y ?? 0) / TILE_HEIGHT),
                ],
                label: property(object, 'label'),
            }),
        );
        this.fixtures = (
            map.getObjectLayer(MAP_LAYERS.fixtures)?.objects ?? []
        ).map((object) => ({
            name: object.name,
            tile: [
                Math.floor((object.x ?? 0) / TILE_HEIGHT),
                Math.floor((object.y ?? 0) / TILE_HEIGHT),
            ],
            kind: property(object, 'kind') ?? '',
            label: property(object, 'label'),
        }));
        // Fixtures are marked like doors: a glowing tile and a name, no art of their own.
        [...this.doors, ...this.fixtures].forEach((place) =>
            this.drawDoor(scene, place),
        );

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

    doorAt(tile: Tile): MapDoor | null {
        return (
            this.doors.find(
                (door) => door.tile[0] === tile[0] && door.tile[1] === tile[1],
            ) ?? null
        );
    }

    fixtureAt(tile: Tile): MapFixture | null {
        return (
            this.fixtures.find(
                (fixture) =>
                    fixture.tile[0] === tile[0] && fixture.tile[1] === tile[1],
            ) ?? null
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
        this.decorations.forEach((object) => object.destroy());
    }

    /** A glowing threshold, and the name of where it leads. */
    private drawDoor(
        scene: Phaser.Scene,
        door: { tile: Tile; label: string | null },
    ): void {
        const { x, y } = this.toWorld(door.tile[0], door.tile[1]);
        const glow = scene.add
            .graphics()
            .fillStyle(DOOR.color, DOOR.alpha)
            .fillPoints(
                [
                    new Phaser.Math.Vector2(x, y - TILE_HEIGHT / 2),
                    new Phaser.Math.Vector2(x + TILE_WIDTH / 2, y),
                    new Phaser.Math.Vector2(x, y + TILE_HEIGHT / 2),
                    new Phaser.Math.Vector2(x - TILE_WIDTH / 2, y),
                ],
                true,
            )
            .setDepth(DEPTH.door);
        this.decorations.push(glow);

        if (door.label) {
            this.decorations.push(
                scene.add
                    .text(x, y - DOOR.labelGapY, door.label, {
                        fontFamily: FONT_FAMILY,
                        fontSize: DOOR.labelFontSize,
                        color: COLORS.paper,
                        stroke: COLORS.night,
                        strokeThickness: DOOR.labelStrokeThickness,
                    })
                    .setOrigin(0.5, 1)
                    .setDepth(DEPTH.labels),
            );
        }
    }
}

function property(
    object: Phaser.Types.Tilemaps.TiledObject,
    name: string,
): string | null {
    const found = (
        (object.properties ?? []) as Array<{ name: string; value: unknown }>
    ).find((p) => p.name === name);

    return typeof found?.value === 'string' ? found.value : null;
}
