import Phaser from 'phaser';
import type { PlacedFurniture } from '../protocol/types';
import type { Placement } from '../shared/buildings';
import type { Footprint } from '../shared/footprint';
import { hides, placeBuilding } from '../shared/buildings';
import { covers } from '../shared/footprint';
import { blocksWalking, facingArt, placedFootprint } from '../shared/furniture';
import { TILE_HEIGHT, TILE_WIDTH } from '../shared/iso';
import { DEPTH, FURNITURE } from '../theme';
import type { IsoMap } from './IsoMap';

/** The texture key an item's art is loaded under, by its URL: new art never reuses an old texture. */
export function itemTexture(url: string): string {
    return `item:${url}`;
}

/** The key of a texture's left-to-right flipped copy (see FurnitureLayer.mirrored). */
export function mirroredTexture(key: string): string {
    return `${key}:mirrored`;
}

type DrawnPiece = {
    placed: PlacedFurniture;
    placement: Placement;
    strips: Phaser.GameObjects.Image[];
    alpha: number;
};

/**
 * The furniture standing in a home, replaced wholesale on every change. Each
 * piece is drawn like a building (see BuildingLayer): from its art for the
 * way it faces, or as a placeholder block the shape of its footprint, cut
 * into strips half a tile wide that sort against avatars on their own (and
 * cut across where a shaped piece leaves a gap; see placeBuilding). Rugs lie
 * under everything.
 */
export class FurnitureLayer {
    private pieces: DrawnPiece[] = [];

    constructor(
        private readonly scene: Phaser.Scene,
        private readonly map: IsoMap,
    ) {}

    set(placed: PlacedFurniture[]): void {
        this.clear();
        this.pieces = placed.map((piece) => this.draw(piece));
    }

    /** Fades any piece tall enough to hide the player's own avatar, as buildings do. */
    update(
        self: {
            box: { left: number; right: number; top: number; bottom: number };
            depthY: number;
        } | null,
        deltaMs: number,
    ): void {
        const step = Math.min(1, deltaMs / FURNITURE.fadeMs);

        for (const piece of this.pieces) {
            const hiding =
                self !== null &&
                blocksWalking(piece.placed) &&
                hides(piece.placement, self.box, self.depthY);
            const target = hiding ? FURNITURE.fadedAlpha : 1;

            if (piece.alpha === target) continue;

            piece.alpha += (target - piece.alpha) * step;

            if (Math.abs(piece.alpha - target) < 0.01) piece.alpha = target;

            piece.strips.forEach((strip) => strip.setAlpha(piece.alpha));
        }
    }

    destroy(): void {
        this.clear();
    }

    private clear(): void {
        this.pieces.forEach(({ strips }) =>
            strips.forEach((strip) => strip.destroy()),
        );
        this.pieces = [];
    }

    private draw(placed: PlacedFurniture): DrawnPiece {
        const footprint = placedFootprint(placed);
        // Art drawn for another size would hang off the piece's tiles: the build
        // can't check it against the catalog, so a placeholder is drawn instead.
        const urls = placed.item.furniture?.art ?? {};
        const key = (facing: number) =>
            urls[facing] ? itemTexture(urls[facing]) : null;
        const fits = (facing: number) => {
            const texture = key(facing);

            return (
                texture !== null &&
                this.scene.textures.exists(texture) &&
                this.scene.textures.get(texture).getSourceImage().width ===
                    (footprint.wide + footprint.deep) * (TILE_WIDTH / 2)
            );
        };
        const art = facingArt(
            placed.rotation,
            fits,
            placed.item.furniture ?? undefined,
        );
        const texture = art
            ? art.mirrored
                ? this.mirrored(key(art.facing) as string)
                : (key(art.facing) as string)
            : this.placeholder(placed, footprint);
        const source = this.scene.textures.get(texture);
        const placement = placeBuilding(
            footprint,
            source.getSourceImage().height,
            this.map.originX,
        );
        const flat = !blocksWalking(placed);
        const strips = placement.strips.map((strip) => {
            const frame = `strip:${strip.offsetX},${strip.offsetY},${strip.width}x${strip.height}`;

            if (!source.has(frame)) {
                source.add(
                    frame,
                    0,
                    strip.offsetX,
                    strip.offsetY,
                    strip.width,
                    strip.height,
                );
            }

            return this.scene.add
                .image(
                    placement.left + strip.offsetX,
                    placement.top + strip.offsetY,
                    texture,
                    frame,
                )
                .setOrigin(0, 0)
                .setDepth(flat ? DEPTH.rugs : DEPTH.avatars + strip.depthY);
        });

        return { placed, placement, strips, alpha: 1 };
    }

    /** Art flipped left to right, for a facing that has none of its own (see facingArt). */
    private mirrored(key: string): string {
        const flipped = mirroredTexture(key);

        if (this.scene.textures.exists(flipped)) return flipped;

        const image = this.scene.textures.get(key).getSourceImage();
        const canvas = this.scene.textures.createCanvas(
            flipped,
            image.width,
            image.height,
        );

        if (!canvas) return key;

        const context = canvas.getContext();
        context.translate(image.width, 0);
        context.scale(-1, 1);
        context.drawImage(image as CanvasImageSource, 0, 0);
        canvas.refresh();
        canvas.setFilter(Phaser.Textures.FilterMode.NEAREST);

        return flipped;
    }

    /**
     * Draws a stand-in for missing art to the size real art would be: a block
     * on each tile the footprint covers, joined where they touch, `height`
     * pixels tall, tinted by the item, with a pale edge along the side it
     * faces so turning it shows.
     */
    private placeholder(placed: PlacedFurniture, footprint: Footprint): string {
        const { slug, swatch, furniture } = placed.item;
        const { wide, deep } = footprint;
        const height = furniture?.height ?? FURNITURE.heightPx;
        const facing = placed.rotation % 4;
        const shape = footprint.mask?.join('/') ?? 'block';
        const key = `furniture-placeholder:${slug}:${swatch ?? ''}:${wide}x${deep}x${height}:${shape}:${facing}`;

        if (this.scene.textures.exists(key)) return key;

        // A colour of a piece is tinted its swatch, so colours tell apart before they have art.
        const base = swatch
            ? Phaser.Display.Color.HexStringToColor(swatch)
            : colourFor(slug, FURNITURE.saturation, FURNITURE.lightness);
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
        const filled = (dx: number, dy: number) =>
            covers({ ...footprint, x: 0, y: 0 }, [dx, dy]);
        // Inset a little, so neighbouring pieces read as separate blocks.
        const near = FURNITURE.insetTiles;

        // The inset shape, as boxes on a finer grid: each covered tile's core,
        // the joins to its covered neighbours, and the middle of any covered
        // 2 × 2. Drawn back to front, each box hides the faces behind it.
        type Box = {
            order: number;
            x0: number;
            x1: number;
            y0: number;
            y1: number;
        };
        const boxes: Box[] = [];
        const core = (d: number) => [d + near, d + 1 - near];
        const join = (d: number) => [d + 1 - near, d + 1 + near];

        for (let dy = 0; dy < deep; dy++) {
            for (let dx = 0; dx < wide; dx++) {
                if (!filled(dx, dy)) continue;

                const right = filled(dx + 1, dy);
                const front = filled(dx, dy + 1);
                const spans: Array<[number[], number[], number]> = [
                    [core(dx), core(dy), 2],
                    ...(right
                        ? [
                              [join(dx), core(dy), 3] as [
                                  number[],
                                  number[],
                                  number,
                              ],
                          ]
                        : []),
                    ...(front
                        ? [
                              [core(dx), join(dy), 3] as [
                                  number[],
                                  number[],
                                  number,
                              ],
                          ]
                        : []),
                    ...(right && front && filled(dx + 1, dy + 1)
                        ? [
                              [join(dx), join(dy), 4] as [
                                  number[],
                                  number[],
                                  number,
                              ],
                          ]
                        : []),
                ];

                for (const [[x0, x1], [y0, y1], step] of spans) {
                    boxes.push({ order: 2 * (dx + dy) + step, x0, x1, y0, y1 });
                }
            }
        }

        const g = this.scene.make.graphics({}, false);

        // Where boxes meet, each reaches a hair into the next so no seam shows.
        // The tops all lie in one plane above every side face, so they go last.
        const seam = 0.02;
        const meets = (box: Box, side: 'x0' | 'x1' | 'y0' | 'y1') =>
            boxes.some((other) =>
                side === 'x0' || side === 'x1'
                    ? other[side === 'x0' ? 'x1' : 'x0'] === box[side] &&
                      other.y0 < box.y1 &&
                      box.y0 < other.y1
                    : other[side === 'y0' ? 'y1' : 'y0'] === box[side] &&
                      other.x0 < box.x1 &&
                      box.x0 < other.x1,
            );
        const grown = boxes
            .sort((a, b) => a.order - b.order)
            .map((box) => ({
                x0: box.x0 - (meets(box, 'x0') ? seam : 0),
                x1: box.x1 + (meets(box, 'x1') ? seam : 0),
                y0: box.y0 - (meets(box, 'y0') ? seam : 0),
                y1: box.y1 + (meets(box, 'y1') ? seam : 0),
                front: box.y1,
                right: box.x1,
            }));

        for (const { x0, x1, y0, y1, front, right } of grown) {
            g.fillStyle(shaded(FURNITURE.shade[0])).fillPoints(
                [
                    at(x0, front, 0),
                    at(x1, front, 0),
                    at(x1, front, height),
                    at(x0, front, height),
                ],
                true,
            );
            g.fillStyle(shaded(FURNITURE.shade[1])).fillPoints(
                [
                    at(right, y0, 0),
                    at(right, y1, 0),
                    at(right, y1, height),
                    at(right, y0, height),
                ],
                true,
            );
        }

        for (const { x0, x1, y0, y1 } of grown) {
            g.fillStyle(base.color).fillPoints(
                [
                    at(x0, y0, height),
                    at(x1, y0, height),
                    at(x1, y1, height),
                    at(x0, y1, height),
                ],
                true,
            );
        }

        // The front edge of the top: +y, −x, −y, then +x as it turns (see
        // FACINGS), along every tile whose neighbour that way is uncovered.
        const [fx, fy] = [
            [0, 1],
            [-1, 0],
            [0, -1],
            [1, 0],
        ][facing];
        g.lineStyle(FURNITURE.frontWidthPx, FURNITURE.frontColor, 1);

        for (let dy = 0; dy < deep; dy++) {
            for (let dx = 0; dx < wide; dx++) {
                if (!filled(dx, dy) || filled(dx + fx, dy + fy)) continue;

                // Along the edge, on into the join with a covered tile beside it.
                const [ex, ey] = [Math.abs(fy), Math.abs(fx)];
                const from = filled(dx - ex, dy - ey) ? -near : near;
                const to = filled(dx + ex, dy + ey) ? 1 + near : 1 - near;
                const edgeX =
                    fx === 0 ? null : fx > 0 ? dx + 1 - near : dx + near;
                const edgeY =
                    fy === 0 ? null : fy > 0 ? dy + 1 - near : dy + near;
                const a = at(edgeX ?? dx + from, edgeY ?? dy + from, height);
                const b = at(edgeX ?? dx + to, edgeY ?? dy + to, height);

                g.lineBetween(a.x, a.y, b.x, b.y);
            }
        }

        g.generateTexture(
            key,
            (wide + deep) * (TILE_WIDTH / 2),
            height + (wide + deep) * (TILE_HEIGHT / 2),
        );
        g.destroy();

        return key;
    }
}

/** A stable colour per slug, so the same piece or building always looks the same. */
export function colourFor(
    slug: string,
    saturation: number,
    lightness: number,
): Phaser.Display.Color {
    let hash = 0;

    for (const char of slug) hash = (hash * 31 + char.charCodeAt(0)) >>> 0;

    return Phaser.Display.Color.HSLToColor(
        (hash % 360) / 360,
        saturation,
        lightness,
    );
}
