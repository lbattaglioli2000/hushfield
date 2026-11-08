import Phaser from 'phaser';
import type { AssetManifest } from '../protocol/types';
import type { Placement } from '../shared/buildings';
import { hidesPlayer, placeBuilding } from '../shared/buildings';
import { TILE_HEIGHT, TILE_WIDTH } from '../shared/iso';
import { BUILDING, COLORS, DEPTH, DOOR } from '../theme';
import { colourFor } from './FurnitureLayer';
import type { IsoMap, MapBuilding } from './IsoMap';
import { placeLabel } from './IsoMap';

/** The texture key building art from the asset manifest is loaded under. */
export function buildingTexture(slug: string): string {
    return `building:${slug}`;
}

/** The texture key a building animation is loaded under, as a spritesheet. */
export function animationTexture(slug: string, part: string): string {
    return `building-animation:${slug}/${part}`;
}

/** A building animation on screen: one piece per strip it overlaps, all on the same frame. */
type DrawnAnimation = {
    pieces: Phaser.GameObjects.Image[];
    frameMs: number[];
    totalMs: number;
    frame: number;
};

/** Over the strip a piece is cut from, and so sorted against avatars as that strip is. */
const ANIMATION_DEPTH_EPSILON = 0.001;

type DrawnBuilding = {
    building: MapBuilding;
    texture: string;
    placement: Placement;
    strips: Phaser.GameObjects.Image[];
    animations: DrawnAnimation[];
    label: Phaser.GameObjects.Text | null;
    alpha: number;
    /** Hiding the player's own avatar: drawn see-through, and taps and hovers pass through it. */
    faded: boolean;
};

/**
 * The buildings standing on a map. Each is drawn from its art, or as a
 * placeholder block until it has some, cut into strips half a tile wide that
 * sort against avatars on their own (see placeBuilding). A building's
 * animations play over its art, cut along the same strips, so each piece
 * sorts like the part of the building it lies on; with reduced motion they
 * hold their first frame.
 */
export class BuildingLayer {
    private readonly drawn: DrawnBuilding[];
    private readonly still = window.matchMedia?.(
        '(prefers-reduced-motion: reduce)',
    ).matches;

    constructor(
        private readonly scene: Phaser.Scene,
        map: IsoMap,
        animations: AssetManifest['animations'],
    ) {
        this.drawn = map.buildings.map((building) => {
            const texture = scene.textures.exists(
                buildingTexture(building.name),
            )
                ? buildingTexture(building.name)
                : this.placeholder(building);
            const source = scene.textures.get(texture);
            const placement = placeBuilding(
                building,
                source.getSourceImage().height,
                map.originX,
            );
            const strips = placement.strips.map((strip, i) => {
                const frame = `strip:${i}`;

                if (!source.has(frame)) {
                    source.add(
                        frame,
                        0,
                        strip.offsetX,
                        0,
                        strip.width,
                        placement.height,
                    );
                }

                return scene.add
                    .image(
                        placement.left + strip.offsetX,
                        placement.top,
                        texture,
                        frame,
                    )
                    .setOrigin(0, 0)
                    .setDepth(DEPTH.avatars + strip.depthY);
            });
            const drawnAnimations =
                texture === buildingTexture(building.name)
                    ? Object.entries(animations[building.name] ?? {})
                          .filter(([part]) =>
                              scene.textures.exists(
                                  animationTexture(building.name, part),
                              ),
                          )
                          .map(([part, animation]) =>
                              this.animate(
                                  animationTexture(building.name, part),
                                  animation,
                                  placement,
                              ),
                          )
                    : [];
            // Named over its door, where door labels go, so it stays on screen however tall the building is.
            const door = map.toWorld(...building.door.tile);
            const label = building.label
                ? placeLabel(
                      scene,
                      door.x,
                      door.y - DOOR.labelGapY,
                      building.label,
                  ).setVisible(false)
                : null;

            return {
                building,
                texture,
                placement,
                strips,
                animations: drawnAnimations,
                label,
                alpha: 1,
                faded: false,
            };
        });
    }

    /**
     * The building drawn at a world point, if any: the front-most one, ignoring
     * its transparent pixels and buildings faded to show the player behind them.
     */
    at(worldX: number, worldY: number): MapBuilding | null {
        let found: { building: MapBuilding; depth: number } | null = null;

        for (const { building, texture, placement, strips, faded } of this
            .drawn) {
            if (faded) continue;

            const x = Math.floor(worldX - placement.left);
            const y = Math.floor(worldY - placement.top);
            const i = Math.floor(x / (TILE_WIDTH / 2));
            const strip = strips[i];

            if (!strip || y < 0 || y >= placement.height) continue;

            const alpha = this.scene.textures.getPixelAlpha(
                x - placement.strips[i].offsetX,
                y,
                texture,
                `strip:${i}`,
            );

            if (alpha > 0 && (!found || strip.depth > found.depth)) {
                found = { building, depth: strip.depth };
            }
        }

        return found?.building ?? null;
    }

    /** Shows the name of the hovered building, if any. */
    hover(building: MapBuilding | null): void {
        this.drawn.forEach(({ building: b, label }) =>
            label?.setVisible(b === building),
        );
    }

    /**
     * Fades any building that is drawn over the player's own avatar, so they
     * can still see themselves behind it; not while they walk in its door.
     */
    update(
        self: Parameters<typeof hidesPlayer>[2] | null,
        deltaMs: number,
    ): void {
        const step = Math.min(1, deltaMs / BUILDING.fadeMs);
        const now = this.scene.time.now;

        for (const drawn of this.drawn) {
            for (const animation of drawn.animations) {
                const frame = this.still ? 0 : frameAt(animation, now);

                if (frame !== animation.frame) {
                    animation.frame = frame;
                    animation.pieces.forEach((piece) => piece.setFrame(frame));
                }
            }

            drawn.faded =
                self !== null &&
                hidesPlayer(drawn.placement, drawn.building.door, self);
            const target = drawn.faded ? BUILDING.fadedAlpha : 1;

            if (drawn.alpha === target) continue;

            drawn.alpha += (target - drawn.alpha) * step;

            if (Math.abs(drawn.alpha - target) < 0.01) drawn.alpha = target;

            drawn.strips.forEach((strip) => strip.setAlpha(drawn.alpha));
            drawn.animations.forEach(({ pieces }) =>
                pieces.forEach((piece) => piece.setAlpha(drawn.alpha)),
            );
        }
    }

    destroy(): void {
        for (const { strips, animations, label } of this.drawn) {
            strips.forEach((strip) => strip.destroy());
            animations.forEach(({ pieces }) =>
                pieces.forEach((piece) => piece.destroy()),
            );
            label?.destroy();
        }
    }

    /**
     * An animation over a building, cropped into one piece for each strip of
     * the building it overlaps, each drawn just over its strip.
     */
    private animate(
        texture: string,
        animation: AssetManifest['animations'][string][string],
        placement: Placement,
    ): DrawnAnimation {
        const { x, y, width, height } = animation;
        const pieces = placement.strips.flatMap((strip) => {
            const left = Math.max(strip.offsetX, x);
            const right = Math.min(strip.offsetX + strip.width, x + width);
            const top = Math.max(strip.offsetY, y);
            const bottom = Math.min(strip.offsetY + strip.height, y + height);

            if (left >= right || top >= bottom) return [];

            return [
                this.scene.add
                    .image(placement.left + x, placement.top + y, texture, 0)
                    .setOrigin(0, 0)
                    .setCrop(left - x, top - y, right - left, bottom - top)
                    .setDepth(
                        DEPTH.avatars + strip.depthY + ANIMATION_DEPTH_EPSILON,
                    ),
            ];
        });

        return {
            pieces,
            frameMs: animation.frame_ms,
            totalMs: animation.frame_ms.reduce((sum, ms) => sum + ms, 0),
            frame: 0,
        };
    }

    /**
     * Draws a stand-in for missing art to the size real art would be: a block
     * on the footprint, its walls `height` pixels tall, with the door on its
     * front edge and a lit window over every tile of the walls.
     */
    private placeholder(building: MapBuilding): string {
        const { name, wide, deep, height, door } = building;
        const key = `building-placeholder:${name}:${wide}x${deep}x${height}`;

        if (this.scene.textures.exists(key)) return key;

        const base = colourFor(name, BUILDING.saturation, BUILDING.lightness);
        const shaded = (factor: number) =>
            Phaser.Display.Color.GetColor(
                base.red * factor,
                base.green * factor,
                base.blue * factor,
            );
        // A point on the footprint's grid (gx, gy tiles from its back corner), lifted `up` pixels.
        const at = (gx: number, gy: number, up: number) =>
            new Phaser.Math.Vector2(
                (gx - gy + deep) * (TILE_WIDTH / 2),
                (gx + gy) * (TILE_HEIGHT / 2) + height - up,
            );
        // A rectangle on the south-west face (along x at gy = deep) or the south-east face (along y at gx = wide).
        const panel = (
            face: 'sw' | 'se',
            from: number,
            to: number,
            bottom: number,
            top: number,
        ) =>
            face === 'sw'
                ? [
                      at(from, deep, bottom),
                      at(to, deep, bottom),
                      at(to, deep, top),
                      at(from, deep, top),
                  ]
                : [
                      at(wide, to, bottom),
                      at(wide, from, bottom),
                      at(wide, from, top),
                      at(wide, to, top),
                  ];
        const doorFace =
            door.deep === 1 && door.y === building.y + deep - 1 ? 'sw' : 'se';
        // Where the door starts along its face, and how many tiles it spans.
        const [doorAlong, doorSpan] =
            doorFace === 'sw'
                ? [door.x - building.x, door.wide]
                : [door.y - building.y, door.deep];
        const doorTop = Math.min(BUILDING.doorHeightPx, height * 0.8);

        const g = this.scene.make.graphics({}, false);
        g.fillStyle(shaded(BUILDING.shade[0])).fillPoints(
            panel('sw', 0, wide, 0, height),
            true,
        );
        g.fillStyle(shaded(BUILDING.shade[1])).fillPoints(
            panel('se', 0, deep, 0, height),
            true,
        );
        g.fillStyle(base.color).fillPoints(
            [
                at(0, 0, height),
                at(wide, 0, height),
                at(wide, deep, height),
                at(0, deep, height),
            ],
            true,
        );

        const windowBottom = doorTop + (height - doorTop) * 0.25;
        const windowTop = doorTop + (height - doorTop) * 0.65;

        if (windowTop - windowBottom >= 8) {
            g.fillStyle(BUILDING.windowColor, BUILDING.windowAlpha);

            for (const [face, length] of [
                ['sw', wide],
                ['se', deep],
            ] as const) {
                for (let i = 0; i < length; i++) {
                    g.fillPoints(
                        panel(face, i + 0.3, i + 0.7, windowBottom, windowTop),
                        true,
                    );
                }
            }
        }

        g.fillStyle(
            Phaser.Display.Color.HexStringToColor(COLORS.night).color,
        ).fillPoints(
            panel(
                doorFace,
                doorAlong + 0.25,
                doorAlong + doorSpan - 0.25,
                0,
                doorTop,
            ),
            true,
        );
        g.lineStyle(2, BUILDING.windowColor, BUILDING.windowAlpha).strokePoints(
            panel(
                doorFace,
                doorAlong + 0.25,
                doorAlong + doorSpan - 0.25,
                0,
                doorTop,
            ),
            true,
        );

        g.generateTexture(
            key,
            (wide + deep) * (TILE_WIDTH / 2),
            height + (wide + deep) * (TILE_HEIGHT / 2),
        );
        g.destroy();

        return key;
    }
}

/** The frame showing `nowMs` into an animation that started at 0 and loops. */
function frameAt(animation: DrawnAnimation, nowMs: number): number {
    let t = nowMs % animation.totalMs;

    for (let n = 0; n < animation.frameMs.length; n++) {
        t -= animation.frameMs[n];

        if (t < 0) return n;
    }

    return 0;
}
