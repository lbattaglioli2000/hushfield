import Phaser from 'phaser';
import type { FixtureKind, Tile } from '../protocol/types';
import type { Grid } from '../shared/astar';
import { isWalkable } from '../shared/astar';
import type { Footprint } from '../shared/footprint';
import { centreOf, covers, tilesOf } from '../shared/footprint';
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

/**
 * A named place on the map, standing on a footprint. `tile` is its middle,
 * where its label goes and taps walk to.
 */
type MapPlace = Footprint & {
    name: string;
    tile: Tile;
    label: string | null;
};

/**
 * A door on the map: one tile, or a row of them drawn in Tiled as a rectangle.
 * Walking onto any of its tiles takes the player through it; the server checks.
 */
export type MapDoor = MapPlace;

/** Something on the map a player can use, such as the shop counter. Walking onto it uses it. */
export type MapFixture = MapPlace & { kind: FixtureKind };

/** A building on the map: walls nobody walks through, and a door on its front edge into the room inside. */
export type MapBuilding = MapPlace & {
    /** How tall the placeholder block stands, in pixels. */
    height: number;
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
    /** The map's diamond, from world (0, 0). */
    private readonly sizePx: { width: number; height: number };
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
        const objects = (layer: string) =>
            map.getObjectLayer(layer)?.objects ?? [];
        this.doors = objects(MAP_LAYERS.exits).map(place);
        this.fixtures = objects(MAP_LAYERS.fixtures).map((object) => ({
            ...place(object),
            // The server checked every fixture's kind when it imported the map.
            kind: property(object, 'kind') as FixtureKind,
        }));
        // The server checked the map: every building has a door, which is one of the exits.
        this.buildings = objects(MAP_LAYERS.buildings).map((object) => {
            const door = this.doors.find(
                (d) => d.name === property(object, 'door'),
            ) as MapDoor;
            const building = place(object);

            return {
                ...building,
                height: numberProperty(object, 'height') ?? TILE_WIDTH,
                label: building.label ?? door.label,
                door,
            };
        });

        // Walls block walking, as on the server; the door is the way in.
        for (const building of this.buildings) {
            for (const [x, y] of tilesOf(building)) {
                if (!covers(building.door, [x, y])) {
                    cells[y * map.width + x] = 0;
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

        this.sizePx = {
            width: (map.width + map.height) * (TILE_WIDTH / 2),
            height: (map.width + map.height) * (TILE_HEIGHT / 2),
        };
        this.fitCamera(scene.cameras.main);
    }

    /**
     * A map that fits in the view stays centred in it; a bigger one scrolls
     * with the player, a little past its edges. Phaser pins a view larger than
     * its bounds to their top-left corner, so a fitting map gets bounds the
     * size of the view, centred on the map.
     */
    fitCamera(camera: Phaser.Cameras.Scene2D.Camera): void {
        const span = (map: number, view: number) =>
            map <= view ? view : map + CAMERA.boundsPadding * 2;
        const width = span(this.sizePx.width, camera.displayWidth);
        const height = span(this.sizePx.height, camera.displayHeight);
        camera.setBounds(
            (this.sizePx.width - width) / 2,
            (this.sizePx.height - height) / 2,
            width,
            height,
        );
    }

    doorAt(tile: Tile): MapDoor | null {
        return this.doors.find((door) => covers(door, tile)) ?? null;
    }

    fixtureAt(tile: Tile): MapFixture | null {
        return this.fixtures.find((fixture) => covers(fixture, tile)) ?? null;
    }

    /** The building a door tile belongs to, if any. */
    buildingAt(tile: Tile): MapBuilding | null {
        return (
            this.buildings.find((building) => covers(building.door, tile)) ??
            null
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

    /** A glowing threshold across all its tiles, and once, over its middle, the name of where it leads. */
    private drawDoor(scene: Phaser.Scene, door: MapPlace): void {
        const { x: ax, y: ay, wide, deep } = door;
        // Tile centres are half a tile in from the corners of the area.
        const corner = (gx: number, gy: number) => {
            const p = this.toWorld(gx - 0.5, gy - 0.5);

            return new Phaser.Math.Vector2(p.x, p.y);
        };
        const glow = scene.add
            .graphics()
            .fillStyle(DOOR.color, DOOR.alpha)
            .fillPoints(
                [
                    corner(ax, ay),
                    corner(ax + wide, ay),
                    corner(ax + wide, ay + deep),
                    corner(ax, ay + deep),
                ],
                true,
            )
            .setDepth(DEPTH.door);
        this.decorations.push(glow);
        const { x, y } = this.toWorld(door.tile[0], door.tile[1]);

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

/**
 * A point, or a rectangle snapped to whole tiles, as a named place. Tiled
 * positions objects on isometric maps in tile-height units on both axes.
 */
function place(object: Phaser.Types.Tilemaps.TiledObject): MapPlace {
    const area = {
        x: Math.floor((object.x ?? 0) / TILE_HEIGHT),
        y: Math.floor((object.y ?? 0) / TILE_HEIGHT),
        wide: Math.max(1, Math.round((object.width ?? 0) / TILE_HEIGHT)),
        deep: Math.max(1, Math.round((object.height ?? 0) / TILE_HEIGHT)),
    };

    return {
        ...area,
        name: object.name,
        tile: centreOf(area),
        label: property(object, 'label'),
    };
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
