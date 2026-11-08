import Phaser from 'phaser';
import type {
    AssetManifest,
    AvatarLook,
    AvatarRig,
    PathState,
    PlayerState,
} from '../protocol/types';
import { pathState } from '../protocol/types';
import type { PathPosition } from '../shared/pathClock';
import { evenPixel, TILE_HEIGHT, TILE_WIDTH } from '../shared/iso';
import { positionOf } from '../shared/pathClock';
import { AVATAR, BUBBLE, COLORS, DEPTH, FONT_FAMILY } from '../theme';
import type { AssetFile } from './AssetLoader';
import type { IsoMap } from './IsoMap';
import { SpeechBubble } from './SpeechBubble';

/** The one tinted sprite drawn when the build has no avatar art. */
export const AVATAR_TEXTURE = 'resident';

/** Time constant for easing out a correction between the drawn and the authoritative position. */
const SMOOTHING_MS = 100;
/** Corrections larger than this (teleport, resync, spawn) snap instead of easing. */
const SNAP_TILES = 1.5;
/** How far above the head anchor the name sits, in game pixels: room for a hat 6 art pixels tall. */
const HAT_ROOM_PX = 14;

type Facing = 'front' | 'back';

/** A pose shown instead of walking or standing. Local only until poses are server state (docs/assets-plan.md §14.5). */
export type AvatarPose = 'stand' | 'sit' | 'wave';

export function avatarBaseTexture(facing: Facing): string {
    return `avatar:base:${facing}`;
}

/** A hair style's or a wearable's part, keyed by its URL: wearable art may later come from uploads. */
function partTexture(url: string): string {
    return `avatar:part:${url}`;
}

/** The base body, for every room; the rest loads per resident (AvatarView.files). */
export function avatarBootFiles(manifest: AssetManifest): AssetFile[] {
    const rig = manifest.avatar;

    if (!rig) return [];

    return (['front', 'back'] as const).map((facing) => ({
        key: avatarBaseTexture(facing),
        url: (m) => m.avatar?.base[facing],
        spritesheet: { frameWidth: rig.frame[0], frameHeight: rig.frame[1] },
    }));
}

/**
 * One player on screen. The authoritative position is always PathClock evaluated
 * on `state`; only the drawn position eases toward it when the state is replaced,
 * so corrections and late events don't show as jumps.
 *
 * With avatar art (the manifest's rig) the resident is a paper doll: the base
 * body, tinted with their skin colour, with hair and what they wear layered
 * over it in the rig's order for the facing. Drawn layers (the base, body and
 * feet) have a frame for each frame of the base; rigid ones (hair, head,
 * face, pin, hand) are one image, drawn on frame 1, moved each frame by how
 * far their anchor moved. Two facings are drawn; the doll is mirrored for the
 * other two. Without the rig it is one tinted placeholder sprite.
 */
export class AvatarView {
    readonly id: number;
    readonly username: string;
    state: PathState;
    lastMessage: { id: number | null; body: string } | null = null;
    private readonly container: Phaser.GameObjects.Container;
    /** Every layer of the doll, snapped to even pixels and mirrored as one. */
    private readonly figure: Phaser.GameObjects.Container;
    /** The base body, or the placeholder sprite: what taps land on. */
    private readonly body: Phaser.GameObjects.Sprite;
    private bubble: SpeechBubble | null = null;
    private readonly onAir: Phaser.GameObjects.Arc;
    /** Drawn minus authoritative position, in tiles; decays to zero. */
    private offset = { x: 0, y: 0 };
    private drawn: { x: number; y: number } | null = null;
    private look: AvatarLook;
    private facing: Facing = 'front';
    private mirrored = false;
    private pose: AvatarPose = 'stand';
    /** Screen pixels walked before the current path, so a walk extended mid-step keeps its step. */
    private walkedBefore = 0;
    private poseSinceMs = 0;
    /** What is drawn now, so layers are rebuilt only when it changes. */
    private drawnKey = '';
    private layers: {
        image: Phaser.GameObjects.Image;
        /** The anchor a rigid layer follows; null for a drawn one. */
        anchor: string | null;
        frames: number;
    }[] = [];
    private readonly still = window.matchMedia?.(
        '(prefers-reduced-motion: reduce)',
    ).matches;

    constructor(
        private readonly scene: Phaser.Scene,
        player: PlayerState,
        isSelf: boolean,
        private readonly rig: AvatarRig | null,
    ) {
        this.id = player.id;
        this.username = player.username;
        this.state = pathState(player);
        this.look = player.avatar;

        this.body = scene.add
            .sprite(
                0,
                0,
                rig ? avatarBaseTexture('front') : AVATAR_TEXTURE,
                rig ? 0 : undefined,
            )
            .setOrigin(0.5, 1);
        this.body.setInteractive({ useHandCursor: !isSelf });
        this.body.setData('userId', player.id);
        this.figure = scene.add.container(0, AVATAR.feetOffsetY, [this.body]);
        this.setLook(player.avatar);

        const top = this.top();
        const label = scene.add
            .text(0, top, player.username, {
                fontFamily: FONT_FAMILY,
                fontSize: AVATAR.nameFontSize,
                color: isSelf ? COLORS.selfName : COLORS.cream,
                stroke: COLORS.night,
                strokeThickness: AVATAR.nameStrokeThickness,
            })
            .setOrigin(0.5, 1);

        this.onAir = scene.add
            .circle(
                -label.width / 2 - AVATAR.onAirRadius - 3,
                top - label.height / 2,
                AVATAR.onAirRadius,
                AVATAR.onAirColor,
            )
            .setStrokeStyle(1, 0x1d2330)
            .setVisible(false);

        this.container = scene.add.container(0, 0, [
            this.figure,
            label,
            this.onAir,
        ]);
    }

    /** The art a look needs beyond the base body: its hair and every worn part. */
    static files(look: AvatarLook, manifest: AssetManifest): AssetFile[] {
        const rig = manifest.avatar;

        if (!rig || !look) return [];

        const urls = [
            ...Object.values(rig.hair[look.hair] ?? {}),
            ...look.worn.flatMap((worn) => Object.values(worn.art)),
        ];

        return urls.flatMap((url) =>
            url === undefined
                ? []
                : [
                      {
                          key: partTexture(url),
                          url: () => url,
                          spritesheet: {
                              frameWidth: rig.frame[0],
                              frameHeight: rig.frame[1],
                          },
                      },
                  ],
        );
    }

    /** The doll's box in world pixels, without the name above it. */
    get bounds(): Phaser.Geom.Rectangle {
        return this.body.getBounds();
    }

    /** What the camera follows. */
    get target(): Phaser.GameObjects.Container {
        return this.container;
    }

    setLook(look: AvatarLook): void {
        this.look = look;
        this.body.setTint(
            Phaser.Display.Color.HexStringToColor(
                look?.body_color ?? COLORS.defaultBody,
            ).color,
        );
        // Rebuilt on the next update, with whatever of its art has loaded.
        this.drawnKey = '';
    }

    /** Sits or waves until the resident walks; a wave ends on its own after a few loops. */
    setPose(pose: AvatarPose, nowMs: number): void {
        this.pose = pose;
        this.poseSinceMs = nowMs;
    }

    /** Replaces the path; the drawn position eases from where it was unless the gap is large. */
    setState(state: PathState, nowMs: number): void {
        const was = positionOf(this.state, nowMs);
        this.walkedBefore = was.finished
            ? 0
            : this.walkedBefore + this.walked(was);
        this.state = state;

        if (state.path.length > 1) this.pose = 'stand';

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
        // The doll alone lands on even game pixels, so its art pixels (2 × 2)
        // line up with the ground's; the name and chat bubble glide unsnapped.
        this.figure.setPosition(
            evenPixel(p.x) - p.x,
            AVATAR.feetOffsetY + evenPixel(p.y) - p.y,
        );
        // Draw nearer (lower on screen) avatars on top.
        this.container.setDepth(DEPTH.avatars + p.y);
        this.turn(pos);

        if (this.rig) {
            this.dress(this.rig);
            this.animate(this.rig, pos, nowMs);
        } else {
            this.figure.setScale(this.mirrored ? -1 : 1, 1);
        }

        return pos;
    }

    /** Lights the ON AIR dot beside the name while this resident talks on voice. */
    setOnAir(on: boolean): void {
        this.onAir.setVisible(on);
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
            this.top() + 2 - BUBBLE.gapY,
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

    /**
     * Where the name sits, above the feet: over the head, with room for a hat
     * (HAT_ROOM_PX), rather than at the top of the frame, which is mostly
     * empty.
     */
    private top(): number {
        if (!this.rig) return -this.body.height - 2;

        const [, head] = this.rig.anchors.front[0].head;

        return -(this.rig.frame[1] - head) - HAT_ROOM_PX;
    }

    /**
     * Faces the way the segment being walked goes on screen, and keeps that
     * facing once the path ends. Front is drawn towards the lower left, back
     * towards the upper right; the other two are those mirrored.
     */
    private turn(pos: PathPosition): void {
        const across = pos.to[0] - pos.from[0] - (pos.to[1] - pos.from[1]);
        const down = pos.to[0] - pos.from[0] + (pos.to[1] - pos.from[1]);

        if (across === 0 && down === 0) return;

        this.facing = down >= 0 ? 'front' : 'back';
        this.mirrored = this.facing === 'front' ? across > 0 : across < 0;
    }

    /** Rebuilds the layers over the base when the look or the facing changed. */
    private dress(rig: AvatarRig): void {
        const parts = this.parts(rig);
        const key = `${this.facing}|${parts.map((p) => p.texture).join(',')}`;

        if (key === this.drawnKey) return;

        this.drawnKey = key;
        this.layers.forEach(({ image }) => image.destroy());
        this.body.setTexture(avatarBaseTexture(this.facing), 0);

        this.layers = parts.map(({ texture, anchor }) => ({
            image: this.scene.add.image(0, 0, texture, 0).setOrigin(0.5, 1),
            anchor,
            frames: this.scene.textures.get(texture).frameTotal - 1,
        }));

        // The rig's order for this facing, the base among them.
        const order = rig.layers[this.facing];
        const sorted = [
            {
                slot: 'base',
                object: this.body as Phaser.GameObjects.GameObject,
            },
            ...parts.map((part, i) => ({
                slot: part.layer,
                object: this.layers[i].image as Phaser.GameObjects.GameObject,
            })),
        ].sort((a, b) => order.indexOf(a.slot) - order.indexOf(b.slot));

        this.figure.removeAll(false);
        this.figure.add(sorted.map((s) => s.object));
    }

    /**
     * The layers the look has for this facing, whose art has loaded: hair,
     * then each worn item's part, and a part drawn behind the body. A slot
     * missing from the rig's order for the facing (a face item from behind)
     * isn't drawn.
     */
    private parts(
        rig: AvatarRig,
    ): { layer: string; texture: string; anchor: string | null }[] {
        const look = this.look;

        if (!look) return [];

        const order = rig.layers[this.facing];
        const wanted = [
            {
                layer: 'hair',
                url: rig.hair[look.hair]?.[this.facing],
                anchor: rig.slots.hair ?? null,
            },
            ...look.worn.flatMap((worn) => [
                {
                    layer: worn.slot,
                    url: worn.art[this.facing],
                    anchor: rig.slots[worn.slot] ?? null,
                },
                {
                    layer: 'behind',
                    url: worn.art[`${this.facing}-behind`],
                    anchor: rig.slots[worn.slot] ?? null,
                },
            ]),
        ];

        return wanted.flatMap(({ layer, url, anchor }) =>
            url &&
            order.includes(layer) &&
            this.scene.textures.exists(partTexture(url))
                ? [{ layer, texture: partTexture(url), anchor }]
                : [],
        );
    }

    /** Picks the frame for this moment and moves the rigid layers to their anchors. */
    private animate(rig: AvatarRig, pos: PathPosition, nowMs: number): void {
        const frame = this.frame(rig, pos, nowMs);
        const anchors = rig.anchors[this.facing];

        this.figure.setScale(this.mirrored ? -1 : 1, 1);
        this.body.setFrame(frame);

        this.layers.forEach(({ image, anchor, frames }) => {
            // A part with every frame is drawn, not moved; a rigid one with its own frames (docs/art/avatars.md) is both.
            if (frames > 1) image.setFrame(Math.min(frame, frames - 1));

            if (!anchor || frames > 1) return;

            const [x0, y0] = anchors[0][anchor];
            const [x, y] = anchors[frame][anchor];
            image.setPosition(x - x0, y - y0);
        });
    }

    /**
     * Walking, the frame comes from how far the resident has gone along the
     * path in screen pixels, a whole cycle every `stride_px`, so every client
     * shows the same step and feet don't slide. Standing, the idle frames
     * play; sitting and waving, their own. With reduced motion, only walking
     * moves: everything else holds its first frame.
     */
    private frame(rig: AvatarRig, pos: PathPosition, nowMs: number): number {
        const { idle, walk, sit, wave } = rig.animations;

        if (!pos.finished) {
            const step = Math.floor(
                ((this.walkedBefore + this.walked(pos)) * walk.frames.length) /
                    rig.stride_px,
            );

            return walk.frames[step % walk.frames.length];
        }

        const playing =
            this.pose === 'sit' ? sit : this.pose === 'wave' ? wave : idle;
        const since = this.pose === 'stand' ? 0 : this.poseSinceMs;

        if (!playing.frame_ms) return playing.frames[0];

        const n = Math.floor((nowMs - since) / playing.frame_ms);

        // A wave is three loops, then the resident stands again: held still with reduced motion, for as long.
        if (this.pose === 'wave' && n >= playing.frames.length * 3) {
            this.pose = 'stand';

            return this.frame(rig, pos, nowMs);
        }

        return playing.frames[this.still ? 0 : n % playing.frames.length];
    }

    /** How far along the path the resident is, in screen pixels. */
    private walked(pos: PathPosition): number {
        const path = this.state.path;
        const screen = (a: number[], b: number[]) =>
            Math.hypot(
                ((b[0] - a[0] - (b[1] - a[1])) * TILE_WIDTH) / 2,
                ((b[0] - a[0] + (b[1] - a[1])) * TILE_HEIGHT) / 2,
            );
        let walked = 0;

        for (let i = 0; i < pos.segment; i++) {
            walked += screen(path[i], path[i + 1]);
        }

        return walked + screen(pos.from, pos.to) * pos.progress;
    }
}
