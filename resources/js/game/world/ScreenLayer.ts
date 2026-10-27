import type Phaser from 'phaser';
import type { EventBus } from '../bus';
import { DEPTH, SCREEN } from '../theme';
import type { IsoMap, ScreenRect } from './IsoMap';

/**
 * A room's TV, hung on the right back wall: drawn here as a dark screen in a
 * bezel, slanted with the wall, and reported each frame as where it is on the
 * glass so the film can play in a frame laid exactly over the screen. The
 * browser draws that frame over the canvas, so nobody can stand in front of
 * the TV; the floor before it is a stage nobody walks on.
 */
export class ScreenLayer {
    private readonly graphics: Phaser.GameObjects.Graphics;
    private last = '';

    constructor(
        private readonly scene: Phaser.Scene,
        private readonly bus: EventBus,
        private readonly screen: ScreenRect,
    ) {
        const { x, y, width, height, slope } = screen;
        // A corner of the screen, `across` pixels right of its left edge and `down` below its top.
        const at = (across: number, down: number) => ({
            x: x + across,
            y: y + down + across * slope,
        });
        const box = (pad: number) => [
            at(-pad, -pad),
            at(width + pad, -pad),
            at(width + pad, height + pad),
            at(-pad, height + pad),
        ];

        this.graphics = scene.add
            .graphics()
            .setDepth(DEPTH.screen)
            .fillStyle(SCREEN.frameColor)
            .fillPoints(box(SCREEN.framePx), true)
            .fillStyle(SCREEN.color)
            .fillPoints(box(0), true)
            // The standby light, on the bezel under the screen's right corner.
            .fillStyle(SCREEN.lightColor)
            .fillCircle(
                at(width - SCREEN.framePx * 2, height + SCREEN.framePx / 2).x,
                at(width - SCREEN.framePx * 2, height + SCREEN.framePx / 2).y,
                SCREEN.lightRadius,
            );
    }

    static forMap(
        scene: Phaser.Scene,
        bus: EventBus,
        map: IsoMap,
    ): ScreenLayer | null {
        if (map.screen) return new ScreenLayer(scene, bus, map.screen);

        bus.emit('cinema-screen-rect', { rect: null, inView: false });

        return null;
    }

    /** Tells the film where the screen is on the glass now, when that changed. */
    update(): void {
        const camera = this.scene.cameras.main;
        const view = camera.worldView;
        const rect = {
            x: Math.round((this.screen.x - view.x) * camera.zoom + camera.x),
            y: Math.round((this.screen.y - view.y) * camera.zoom + camera.y),
            width: Math.round(this.screen.width * camera.zoom),
            height: Math.round(this.screen.height * camera.zoom),
            slope: this.screen.slope,
        };
        // The film plays while any of the TV is on the glass; the glass crops the rest.
        const inView =
            rect.x < camera.x + camera.width &&
            rect.x + rect.width > camera.x &&
            rect.y < camera.y + camera.height &&
            rect.y + rect.height + rect.width * rect.slope > camera.y;
        const key = `${rect.x},${rect.y},${rect.width},${rect.height},${inView}`;

        if (key === this.last) return;

        this.last = key;
        this.bus.emit('cinema-screen-rect', { rect, inView });
    }

    destroy(): void {
        this.graphics.destroy();
        this.bus.emit('cinema-screen-rect', { rect: null, inView: false });
    }
}
