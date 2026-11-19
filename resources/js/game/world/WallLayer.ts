import Phaser from 'phaser';
import type { AssetManifest } from '../protocol/types';
import type { WallPanel, WallSide } from '../shared/walls';
import { placePanel, wallPanels } from '../shared/walls';
import { TILE_HEIGHT, TILE_WIDTH } from '../shared/iso';
import { DEPTH, WALL } from '../theme';
import type { PlayingAnimation } from './BuildingLayer';
import { frameAt, showFrame } from './BuildingLayer';
import { colourFor } from './FurnitureLayer';
import type { IsoMap } from './IsoMap';

/**
 * The texture key wall art from the asset manifest is loaded under, by style
 * and part: `{style}/{side}`, or `{style}/{side}-door` for a doorway.
 */
export function wallTexture(name: string): string {
    return `wall:${name}`;
}

/** The texture key a set of wall lights is loaded under, by set and side: `{set}/{side}`. */
export function lightsTexture(name: string): string {
    return `wall-lights:${name}`;
}

/** Just over the walls they hang on. */
const LIGHTS_DEPTH = DEPTH.walls + 0.01;

/** A panel is one tile edge across: half a tile wide on screen. */
const PANEL_WIDTH = TILE_WIDTH / 2;

/**
 * A room's back walls, when its map names a style: one panel per tile of the
 * back row and column, drawn from the style's art or as a tinted placeholder.
 * Nothing stands behind them, so they lie under everything that stands and
 * never fade. A doorway is the style's door art, a placeholder with an
 * opening, or, for a style with art but no door art, a gap. A map that names
 * a set of lights strings them along the top of every panel drawn, a panel's
 * worth each, twinkling in step; with reduced motion they hold the first frame.
 */
export class WallLayer {
    private readonly panels: Phaser.GameObjects.Image[] = [];
    private readonly lights: PlayingAnimation[] = [];
    private readonly still = window.matchMedia?.(
        '(prefers-reduced-motion: reduce)',
    ).matches;

    constructor(
        private readonly scene: Phaser.Scene,
        map: IsoMap,
        lights: NonNullable<AssetManifest['lights']>,
    ) {
        const style = map.walls;

        if (!style) return;

        const strand = map.wallLights ? lights[map.wallLights] : undefined;
        const bulbs: Record<WallSide, Phaser.GameObjects.Image[]> = {
            left: [],
            right: [],
        };

        for (const panel of wallPanels(
            map.grid.width,
            map.grid.height,
            map.doors,
            map.wallSides,
        )) {
            const texture = this.texture(style, panel, map.wallHeight);

            if (!texture) continue;

            const { left, top } = placePanel(
                panel,
                scene.textures.get(texture).getSourceImage().height,
                map.originX,
            );
            this.panels.push(
                scene.add
                    .image(left, top, texture)
                    .setOrigin(0, 0)
                    .setDepth(DEPTH.walls),
            );

            // Each side's strand falls across a panel as its top edge does, so it hangs from the top left corner.
            const key = lightsTexture(`${map.wallLights}/${panel.side}`);

            if (strand?.[panel.side] && scene.textures.exists(key)) {
                bulbs[panel.side].push(
                    scene.add
                        .image(left, top + WALL.lightsDropPx, key, 0)
                        .setOrigin(0, 0)
                        .setDepth(LIGHTS_DEPTH),
                );
            }
        }

        for (const [side, pieces] of Object.entries(bulbs)) {
            const timing = strand?.[side];

            if (!timing || pieces.length === 0) continue;

            this.lights.push({
                pieces,
                frameMs: timing.frame_ms,
                totalMs: timing.frame_ms.reduce((sum, ms) => sum + ms, 0),
                frame: 0,
            });
        }
    }

    update(): void {
        for (const lights of this.lights) {
            showFrame(
                lights,
                this.still ? 0 : frameAt(lights, this.scene.time.now),
            );
        }
    }

    destroy(): void {
        this.panels.forEach((panel) => panel.destroy());
        this.lights.forEach(({ pieces }) =>
            pieces.forEach((bulb) => bulb.destroy()),
        );
    }

    private texture(
        style: string,
        { side, doorway }: WallPanel,
        wallHeight: number,
    ): string | null {
        const name = `${style}/${side}`;
        const art = (file: string) =>
            this.scene.textures.exists(wallTexture(file))
                ? wallTexture(file)
                : null;

        if (doorway) {
            return (
                art(`${name}-door`) ??
                (art(name)
                    ? null
                    : this.placeholder(style, side, true, wallHeight))
            );
        }

        return art(name) ?? this.placeholder(style, side, false, wallHeight);
    }

    /**
     * A stand-in panel the size real art would be: a face as tall as
     * the map's wall height (WALL.heightPx unless it says) on a canvas one tile edge across, tinted by the style's
     * name, with a pale top band and a dark skirting board; a doorway is open
     * up to WALL.doorHeightPx between narrow jambs.
     */
    private placeholder(
        style: string,
        side: WallSide,
        doorway: boolean,
        wallHeight: number,
    ): string {
        const key = `wall-placeholder:${style}:${side}:${wallHeight}${doorway ? ':door' : ''}`;

        if (this.scene.textures.exists(key)) return key;

        const height = wallHeight + TILE_HEIGHT / 2;
        const base = colourFor(style, WALL.saturation, WALL.lightness);
        const tint = (factor: number) =>
            Phaser.Display.Color.GetColor(
                Math.min(255, base.red * WALL.shade[side] * factor),
                Math.min(255, base.green * WALL.shade[side] * factor),
                Math.min(255, base.blue * WALL.shade[side] * factor),
            );
        // A point on the face, `along` (0–1) from its left end and `up` pixels above the floor.
        const at = (along: number, up: number) =>
            new Phaser.Math.Vector2(
                along * PANEL_WIDTH,
                height -
                    up -
                    (side === 'left'
                        ? along * (TILE_HEIGHT / 2)
                        : (1 - along) * (TILE_HEIGHT / 2)),
            );
        const g = this.scene.make.graphics({}, false);
        const band = (
            from: number,
            to: number,
            bottom: number,
            top: number,
            colour: number,
        ) =>
            g
                .fillStyle(colour)
                .fillPoints(
                    [
                        at(from, bottom),
                        at(to, bottom),
                        at(to, top),
                        at(from, top),
                    ],
                    true,
                );
        // The face in pieces, so a doorway can leave its middle open.
        const strips: Array<[number, number, number]> = doorway
            ? [
                  [0, WALL.jambTiles, 0],
                  [1 - WALL.jambTiles, 1, 0],
                  [WALL.jambTiles, 1 - WALL.jambTiles, WALL.doorHeightPx],
              ]
            : [[0, 1, 0]];

        for (const [from, to, bottom] of strips) {
            band(from, to, bottom, wallHeight, tint(1));

            if (bottom === 0)
                band(from, to, 0, WALL.skirtingPx, tint(WALL.skirtingDarken));
        }

        band(
            0,
            1,
            wallHeight - WALL.trimPx,
            wallHeight,
            tint(WALL.trimLighten),
        );
        g.generateTexture(key, PANEL_WIDTH, height);
        g.destroy();

        return key;
    }
}
