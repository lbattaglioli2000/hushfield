import Phaser from 'phaser';
import type { AvatarLook, PathState, PlayerState } from '../protocol/types';
import { pathState } from '../protocol/types';
import type { PathPosition } from '../shared/pathClock';
import { positionOf } from '../shared/pathClock';
import { AVATAR, BUBBLE, COLORS, DEPTH, FONT_FAMILY } from '../theme';
import type { IsoMap } from './IsoMap';
import { SpeechBubble } from './SpeechBubble';

export const AVATAR_TEXTURE = 'resident';

/** Time constant for easing out a correction between the drawn and the authoritative position. */
const SMOOTHING_MS = 100;
/** Corrections larger than this (teleport, resync, spawn) snap instead of easing. */
const SNAP_TILES = 1.5;

/**
 * One player on screen. The authoritative position is always PathClock evaluated
 * on `state`; only the drawn position eases toward it when the state is replaced,
 * so corrections and late events don't show as jumps.
 *
 * The look is a single tinted sprite for now; paper-doll layers go in here.
 */
export class AvatarView {
    readonly id: number;
    readonly username: string;
    state: PathState;
    lastMessage: { id: number | null; body: string } | null = null;
    private readonly container: Phaser.GameObjects.Container;
    private readonly body: Phaser.GameObjects.Image;
    private bubble: SpeechBubble | null = null;
    /** Drawn minus authoritative position, in tiles; decays to zero. */
    private offset = { x: 0, y: 0 };
    private drawn: { x: number; y: number } | null = null;

    constructor(
        private readonly scene: Phaser.Scene,
        player: PlayerState,
        isSelf: boolean,
    ) {
        this.id = player.id;
        this.username = player.username;
        this.state = pathState(player);

        this.body = scene.add
            .image(0, AVATAR.feetOffsetY, AVATAR_TEXTURE)
            .setOrigin(0.5, 1);
        this.setLook(player.avatar);
        this.body.setInteractive({ useHandCursor: !isSelf });
        this.body.setData('userId', player.id);

        const label = scene.add
            .text(0, -this.body.height - 2, player.username, {
                fontFamily: FONT_FAMILY,
                fontSize: AVATAR.nameFontSize,
                color: isSelf ? COLORS.selfName : COLORS.cream,
                stroke: COLORS.night,
                strokeThickness: AVATAR.nameStrokeThickness,
            })
            .setOrigin(0.5, 1);

        this.container = scene.add.container(0, 0, [this.body, label]);
    }

    /** What the camera follows. */
    get target(): Phaser.GameObjects.Container {
        return this.container;
    }

    setLook(look: AvatarLook): void {
        this.body.setTint(
            Phaser.Display.Color.HexStringToColor(
                look?.body_color ?? COLORS.defaultBody,
            ).color,
        );
    }

    /** Replaces the path; the drawn position eases from where it was unless the gap is large. */
    setState(state: PathState, nowMs: number): void {
        this.state = state;

        if (!this.drawn) return;

        const target = positionOf(state, nowMs);
        const dx = this.drawn.x - target.x;
        const dy = this.drawn.y - target.y;
        const snap = Math.hypot(dx, dy) > SNAP_TILES;
        this.offset = snap ? { x: 0, y: 0 } : { x: dx, y: dy };
    }

    /** Positions the avatar for this frame and returns its authoritative position. */
    update(nowMs: number, deltaMs: number, map: IsoMap): PathPosition {
        const pos = positionOf(this.state, nowMs);
        const decay = Math.exp(-deltaMs / SMOOTHING_MS);
        this.offset.x *= decay;
        this.offset.y *= decay;
        this.drawn = { x: pos.x + this.offset.x, y: pos.y + this.offset.y };

        const p = map.toWorld(this.drawn.x, this.drawn.y);
        this.container.setPosition(p.x, p.y);
        // Draw nearer (lower on screen) avatars on top.
        this.container.setDepth(DEPTH.avatars + p.y);
        this.body.setFlipX(pos.to[0] - pos.from[0] < pos.to[1] - pos.from[1]);

        return pos;
    }

    setIdle(idle: boolean): void {
        this.container.setAlpha(idle ? AVATAR.idleAlpha : 1);
    }

    say(messageId: number | null, body: string, seconds: number): void {
        this.lastMessage = { id: messageId, body };
        this.bubble?.destroy();

        const bubble: SpeechBubble = new SpeechBubble(
            this.scene,
            this.container,
            -this.body.height - BUBBLE.gapY,
            body,
            this.id,
            seconds,
            () => {
                if (this.bubble === bubble) this.bubble = null;
            },
        );
        this.bubble = bubble;
    }

    destroy(): void {
        this.bubble?.destroy();
        this.container.destroy(true);
    }
}
