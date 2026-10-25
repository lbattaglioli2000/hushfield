import Phaser from 'phaser';
import type { Tile } from '../protocol/types';
import type { Grid } from '../shared/astar';
import { isWalkable } from '../shared/astar';
import type { Footprint } from '../shared/buildings';
import { covers } from '../shared/buildings';
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
    buildings: 'buildings',
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

/** A building on the map: walls nobody walks through, and a door on its front edge into the room inside. */
export type MapBuilding = Footprint & {
    name: string;
    /** How tall the placeholder block stands, in pixels. */
    height: number;
    label: string | null;
    door: MapDoor;
};

/** One room's isometric tilemap: the drawn ground layer and doors, the walkable grid, and tile↔world conversion. */
export class IsoMap {
    readonly key: string;
    readonly grid: Grid;
    /** World x of tile (0,0)'s top corner. */
    readonly originX: number;
    readonly doors: MapDoor[];
    readonly fixtures: MapFixture[];
    readonly buildings: MapBuilding[];
    private readonly layer: Phaser.Tilemaps.TilemapLayer | null = null;
    private readonly decorations: Phaser.GameObjects.GameObject[] = [];
    private readonly labels: Array<{
        tile: Tile;
        text: Phaser.GameObjects.Text;
    }> = [];

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
        // The server checked the map: every building has a door, which is one of the exits.
        this.buildings = (
            map.getObjectLayer(MAP_LAYERS.buildings)?.objects ?? []
        ).map((object) => {
            const door = this.doors.find(
                (d) => d.name === property(object, 'door'),
            ) as MapDoor;

            return {
                name: object.name,
                x: Math.round((object.x ?? 0) / TILE_HEIGHT),
                y: Math.round((object.y ?? 0) / TILE_HEIGHT),
                wide: Math.round((object.width ?? 0) / TILE_HEIGHT),
                deep: Math.round((object.height ?? 0) / TILE_HEIGHT),
                height: numberProperty(object, 'height') ?? TILE_WIDTH,
                label: property(object, 'label') ?? door.label,
                door,
            };
        });

        // Walls block walking, as on the server; the door is the way in.
        for (const building of this.buildings) {
            for (let y = building.y; y < building.y + building.deep; y++) {
                for (let x = building.x; x < building.x + building.wide; x++) {
                    if (
                        x !== building.door.tile[0] ||
                        y !== building.door.tile[1]
                    ) {
                        cells[y * map.width + x] = 0;
                    }
                }
            }
        }

        // Fixtures are marked like doors: a glowing tile and a name, no art of their own.
        // A building's door is drawn on its wall instead, and named when the building is hovered.
        [
            ...this.doors.filter(
                (door) =>
                    !this.buildings.some((building) =>
                        covers(building, door.tile),
                    ),
            ),
            ...this.fixtures,
        ].forEach((place) => this.drawDoor(scene, place));

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

    /** The building a door tile belongs to, if any. */
    buildingAt(tile: Tile): MapBuilding | null {
        return (
            this.buildings.find(
                (building) =>
                    building.door.tile[0] === tile[0] &&
                    building.door.tile[1] === tile[1],
            ) ?? null
        );
    }

    /**
     * Hides door and fixture labels while someone stands within a tile of them,
     * where the label would sit on top of their name.
     */
    hideLabelsNear(positions: Array<{ x: number; y: number }>): void {
        for (const { tile, text } of this.labels) {
            text.setVisible(
                !positions.some(
                    (p) =>
                        Math.abs(p.x - tile[0]) < 1.5 &&
                        Math.abs(p.y - tile[1]) < 1.5,
                ),
            );
        }
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
            const text = placeLabel(scene, x, y - DOOR.labelGapY, door.label);
            this.decorations.push(text);
            this.labels.push({ tile: door.tile, text });
        }
    }
}

/** A place's name floating over it, in the style doors and buildings share. */
export function placeLabel(
    scene: Phaser.Scene,
    x: number,
    y: number,
    label: string,
): Phaser.GameObjects.Text {
    return scene.add
        .text(x, y, label, {
            fontFamily: FONT_FAMILY,
            fontSize: DOOR.labelFontSize,
            color: COLORS.paper,
            stroke: COLORS.night,
            strokeThickness: DOOR.labelStrokeThickness,
        })
        .setOrigin(0.5, 1)
        .setDepth(DEPTH.labels);
}

function numberProperty(
    object: Phaser.Types.Tilemaps.TiledObject,
    name: string,
): number | null {
    const value = rawProperty(object, name);

    return typeof value === 'number' ? value : null;
}

function property(
    object: Phaser.Types.Tilemaps.TiledObject,
    name: string,
): string | null {
    const value = rawProperty(object, name);

    return typeof value === 'string' ? value : null;
}

function rawProperty(
    object: Phaser.Types.Tilemaps.TiledObject,
    name: string,
): unknown {
    return (
        (object.properties ?? []) as Array<{ name: string; value: unknown }>
    ).find((p) => p.name === name)?.value;
}
