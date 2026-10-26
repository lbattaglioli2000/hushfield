import Phaser from 'phaser';
import type { PlacedFurniture, Tile } from '../protocol/types';
import type { Footprint, Placement } from '../shared/buildings';
import { hides, placeBuilding } from '../shared/buildings';
import {
    blocksWalking,
    facingArt,
    pieceAt,
    placedFootprint,
} from '../shared/furniture';
import { TILE_HEIGHT, TILE_WIDTH } from '../shared/iso';
import { DEPTH, FURNITURE } from '../theme';
import type { IsoMap } from './IsoMap';

/** The texture key furniture art from the asset manifest is loaded under, by file name. */
export function furnitureTexture(name: string): string {
    return `furniture:${name}`;
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
 * way it faces, or as a placeholder block the size of its footprint, cut
 * into strips half a tile wide that sort against avatars on their own. Rugs
 * lie under everything.
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

    /** The piece standing on a tile, if any: the one on top when a piece stands on a rug. */
    at(tile: Tile): PlacedFurniture | null {
        return pieceAt(
            this.pieces.map(({ placed }) => placed),
            tile,
        );
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
        const art = facingArt(placed.item.slug, placed.rotation, (name) =>
            this.scene.textures.exists(furnitureTexture(name)),
        );
        const texture = art
            ? art.mirrored
                ? this.mirrored(furnitureTexture(art.name))
                : furnitureTexture(art.name)
            : this.placeholder(placed, footprint);
        const source = this.scene.textures.get(texture);
        const placement = placeBuilding(
            footprint,
            source.getSourceImage().height,
            this.map.originX,
        );
        const flat = !blocksWalking(placed);
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

            return this.scene.add
                .image(
                    placement.left + strip.offsetX,
                    placement.top,
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
        const flipped = `${key}:mirrored`;

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

        return flipped;
    }

    /**
     * Draws a stand-in for missing art to the size real art would be: a block
     * on the footprint, `height` pixels tall, tinted by the item, with a pale
     * edge along the side it faces so turning it shows.
     */
    private placeholder(placed: PlacedFurniture, footprint: Footprint): string {
        const { slug, furniture } = placed.item;
        const { wide, deep } = footprint;
        const height = furniture?.height ?? FURNITURE.heightPx;
        const facing = placed.rotation % 4;
        const key = `furniture-placeholder:${slug}:${wide}x${deep}x${height}:${facing}`;

        if (this.scene.textures.exists(key)) return key;

        const base = colourFor(slug, FURNITURE.saturation, FURNITURE.lightness);
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
        // Inset a little, so neighbouring pieces read as separate blocks.
        const near = FURNITURE.insetTiles;
        const farX = wide - near;
        const farY = deep - near;

        const g = this.scene.make.graphics({}, false);
        g.fillStyle(shaded(FURNITURE.shade[0])).fillPoints(
            [
                at(near, farY, 0),
                at(farX, farY, 0),
                at(farX, farY, height),
                at(near, farY, height),
            ],
            true,
        );
        g.fillStyle(shaded(FURNITURE.shade[1])).fillPoints(
            [
                at(farX, near, 0),
                at(farX, farY, 0),
                at(farX, farY, height),
                at(farX, near, height),
            ],
            true,
        );
        g.fillStyle(base.color).fillPoints(
            [
                at(near, near, height),
                at(farX, near, height),
                at(farX, farY, height),
                at(near, farY, height),
            ],
            true,
        );

        // The front edge of the top: +y, −x, −y, then +x as it turns (see FACINGS).
        const front = [
            [at(near, farY, height), at(farX, farY, height)],
            [at(near, near, height), at(near, farY, height)],
            [at(near, near, height), at(farX, near, height)],
            [at(farX, near, height), at(farX, farY, height)],
        ][facing];
        g.lineStyle(
            FURNITURE.frontWidthPx,
            FURNITURE.frontColor,
            1,
        ).lineBetween(front[0].x, front[0].y, front[1].x, front[1].y);

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
