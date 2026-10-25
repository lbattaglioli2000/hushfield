import Phaser from 'phaser';
import { BUBBLE, COLORS, FONT_FAMILY } from '../theme';

/**
 * A chat bubble inside an avatar's container that fades out after `seconds`.
 * Its background carries `userId` so tapping it targets the speaker.
 */
export class SpeechBubble {
    private readonly container: Phaser.GameObjects.Container;
    private readonly tween: Phaser.Tweens.Tween;

    constructor(
        scene: Phaser.Scene,
        parent: Phaser.GameObjects.Container,
        bottomY: number,
        body: string,
        userId: number,
        seconds: number,
        onExpired: () => void,
    ) {
        const pad = BUBBLE.padding;
        const text = scene.add
            .text(0, 0, body, {
                fontFamily: FONT_FAMILY,
                fontSize: BUBBLE.fontSize,
                color: COLORS.night,
                wordWrap: { width: BUBBLE.wrapWidth },
                align: 'center',
            })
            .setOrigin(0.5, 1);
        const background = scene.add
            .rectangle(
                0,
                pad,
                text.width + pad * 2,
                text.height + pad * 2,
                Phaser.Display.Color.HexStringToColor(COLORS.paper).color,
            )
            .setOrigin(0.5, 1)
            .setStrokeStyle(
                1,
                Phaser.Display.Color.HexStringToColor(COLORS.night).color,
                BUBBLE.strokeAlpha,
            )
            .setInteractive();
        background.setData('userId', userId);

        this.container = scene.add.container(0, bottomY, [background, text]);
        parent.add(this.container);
        this.tween = scene.tweens.add({
            targets: this.container,
            alpha: 0,
            delay: Math.max(0, seconds * 1000 - BUBBLE.fadeMs),
            duration: BUBBLE.fadeMs,
            onComplete: () => {
                this.container.destroy(true);
                onExpired();
            },
        });
    }

    destroy(): void {
        this.tween.remove();
        this.container.destroy(true);
    }
}
