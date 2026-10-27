import type Phaser from 'phaser';
import type { EventBus } from '../bus';
import { DEPTH, HOUSE_LIGHTS } from '../theme';

/**
 * A theater's house lights: a shade over the room, the floor, its walls and
 * everyone in it, under the names and the film. How dark comes with the
 * cinema's state; it fades there, like lights on a dimmer.
 */
export class HouseLights {
    private readonly shade: Phaser.GameObjects.Rectangle;
    private tween: Phaser.Tweens.Tween | null = null;
    private target = 0;

    constructor(
        private readonly scene: Phaser.Scene,
        bus: EventBus,
    ) {
        this.shade = scene.add
            .rectangle(0, 0, 1, 1, HOUSE_LIGHTS.color)
            .setScrollFactor(0)
            .setDepth(DEPTH.houseLights)
            .setAlpha(0);
        this.fit();

        const off = bus.on('cinema-state', ({ state }) =>
            this.set(state?.darkness ?? 0, state !== null),
        );
        scene.scale.on('resize', this.fit);
        scene.events.once('destroy', () => {
            off();
            scene.scale.off('resize', this.fit);
        });
    }

    /** Fades to `darkness`; outside a theater the shade lifts at once. */
    private set(darkness: number, inTheater: boolean): void {
        if (darkness === this.target) return;

        this.target = darkness;
        this.tween?.stop();
        this.tween = null;

        if (!inTheater) {
            this.shade.setAlpha(darkness);

            return;
        }

        this.tween = this.scene.tweens.add({
            targets: this.shade,
            alpha: darkness,
            duration: HOUSE_LIGHTS.fadeMs,
            ease: 'Sine.easeInOut',
        });
    }

    /** Centred on the view and oversized, so it still covers it at any camera zoom. */
    private readonly fit = (): void => {
        const { width, height } = this.scene.scale;

        this.shade
            .setPosition(width / 2, height / 2)
            .setSize(width * 4, height * 4)
            .setDisplayOrigin(width * 2, height * 2);
    };
}
