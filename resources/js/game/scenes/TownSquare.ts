import Phaser from 'phaser';
import type { Grid } from '../astar';
import { findPath, isWalkable } from '../astar';
import type { EventBus } from '../bus';
import { screenToTile, TILE_HEIGHT, TILE_WIDTH, tileToScreen } from '../iso';
import type { WorldNet, WorldListener } from '../net';
import { RefusedError } from '../net';
import { positionAt } from '../pathClock';
import type {
    AssetManifest,
    AvatarLook,
    ChatMessageSentEvent,
    PathState,
    PlayerJoinedEvent,
    PlayerLeftEvent,
    PlayerMovedEvent,
    PlayerState,
    Snapshot,
    Tile,
} from '../protocol';

export type SceneOptions = {
    manifest: AssetManifest;
    bubbleSeconds: number;
    maxPathSteps: number;
    copy: Record<string, string>;
};

type Avatar = {
    id: number;
    username: string;
    state: PathState;
    container: Phaser.GameObjects.Container;
    body: Phaser.GameObjects.Image;
    bubble: Phaser.GameObjects.Container | null;
    bubbleTween: Phaser.Tweens.Tween | null;
    lastMessage: { id: number | null; body: string } | null;
};

const TAP_SLOP_PX = 10;

/**
 * Draws the Town Square. Every position is evaluated from server-confirmed
 * paths through PathClock each frame; the scene never invents positions of its own.
 */
export class TownSquare extends Phaser.Scene implements WorldListener {
    private net!: WorldNet;
    private bus!: EventBus;
    private options!: SceneOptions;
    private avatars = new Map<number, Avatar>();
    private selfId = 0;
    private grid: Grid | null = null;
    private originX = 0;
    private mapKey: string | null = null;
    private layer: Phaser.Tilemaps.TilemapLayer | null = null;
    private hover!: Phaser.GameObjects.Image;
    private marker!: Phaser.GameObjects.Image;
    private pendingSnapshot: Snapshot | null = null;
    private ready = false;

    constructor() {
        super('TownSquare');
    }

    init(data: { net: WorldNet; bus: EventBus; options: SceneOptions }): void {
        this.net = data.net;
        this.bus = data.bus;
        this.options = data.options;
    }

    preload(): void {
        const { images } = this.options.manifest;
        this.load.spritesheet('tiles', images.tiles, {
            frameWidth: TILE_WIDTH,
            frameHeight: TILE_HEIGHT,
        });
        this.load.image('resident', images.resident);
        this.load.image('marker', images.marker);
        this.load.image('hover', images.hover);
    }

    create(): void {
        this.cameras.main.setBackgroundColor('#1d2330');
        this.hover = this.add.image(0, 0, 'hover').setVisible(false).setDepth(1);
        this.marker = this.add.image(0, 0, 'marker').setVisible(false).setDepth(2);

        this.input.on('pointermove', (pointer: Phaser.Input.Pointer) => {
            const tile = this.pointerTile(pointer);
            const show = tile !== null && this.grid !== null && isWalkable(this.grid, ...tile);
            this.hover.setVisible(show && !pointer.wasTouch);

            if (show && tile) {
                const p = tileToScreen(tile[0], tile[1], this.originX);
                this.hover.setPosition(p.x, p.y);
            }
        });

        this.input.on(
            'pointerup',
            (pointer: Phaser.Input.Pointer, over: Phaser.GameObjects.GameObject[]) =>
                this.onTap(pointer, over),
        );

        this.ready = true;

        if (this.pendingSnapshot) {
            this.snapshot(this.pendingSnapshot, this.selfId);
            this.pendingSnapshot = null;
        }
    }

    update(): void {
        const now = this.net.serverNow();
        const self = this.avatars.get(this.selfId);

        for (const avatar of this.avatars.values()) {
            const { path, started_at_ms, speed } = avatar.state;
            const pos = positionAt(path, started_at_ms, speed, now);
            const p = tileToScreen(pos.x, pos.y, this.originX);
            avatar.container.setPosition(p.x, p.y);
            // Draw nearer (lower on screen) avatars on top.
            avatar.container.setDepth(10 + p.y);
            avatar.body.setFlipX(pos.to[0] - pos.from[0] < pos.to[1] - pos.from[1]);
        }

        if (self && this.marker.visible) {
            const last = self.state.path[self.state.path.length - 1];
            const pos = positionAt(self.state.path, self.state.started_at_ms, self.state.speed, now);

            if (pos.finished && pos.tile[0] === last[0] && pos.tile[1] === last[1]) {
                this.marker.setVisible(false);
            }
        }
    }

    // ── WorldListener ─────────────────────────────────────────────────

    snapshot(snapshot: Snapshot, selfId: number): void {
        this.selfId = selfId;

        if (!this.ready) {
            this.pendingSnapshot = snapshot;

            return;
        }

        const key = `map:${snapshot.room.slug}:${snapshot.room.map_version}`;

        if (this.mapKey !== key) {
            if (!this.cache.tilemap.exists(key)) {
                this.load.tilemapTiledJSON(key, snapshot.room.map_url);
                this.load.once(Phaser.Loader.Events.COMPLETE, () => this.snapshot(snapshot, selfId));
                this.load.start();

                return;
            }

            this.buildMap(key);
        }

        // Replace local state wholesale: the snapshot is the truth.
        const seen = new Set<number>();

        for (const player of snapshot.players) {
            seen.add(player.id);
            this.upsert(player);
        }

        for (const id of [...this.avatars.keys()]) {
            if (!seen.has(id)) this.remove(id);
        }

        const self = this.avatars.get(selfId);

        if (self) this.cameras.main.startFollow(self.container, true, 0.12, 0.12);
    }

    joined(event: PlayerJoinedEvent): void {
        this.upsert(event.player);
    }

    left(event: PlayerLeftEvent): void {
        if (event.user_id !== this.selfId) this.remove(event.user_id);
    }

    moved(event: PlayerMovedEvent): void {
        const avatar = this.avatars.get(event.user_id);

        if (avatar) {
            avatar.state = { path: event.path, started_at_ms: event.started_at_ms, speed: event.speed };
        }
    }

    chat(event: ChatMessageSentEvent): void {
        this.say(event.user_id, event.id, event.body);
    }

    idle(userId: number, idle: boolean): void {
        this.avatars.get(userId)?.container.setAlpha(idle ? 0.45 : 1);
    }

    /** Shows the player's own line straight away, including when muted (only they see it then). */
    sayOwn(id: number | null, body: string): void {
        this.say(this.selfId, id, body);
    }

    // ── Movement ──────────────────────────────────────────────────────

    private onTap(pointer: Phaser.Input.Pointer, over: Phaser.GameObjects.GameObject[]): void {
        if (Phaser.Math.Distance.Between(pointer.downX, pointer.downY, pointer.upX, pointer.upY) > TAP_SLOP_PX) {
            return;
        }

        this.bus.emit('input-activity', {});

        const clicked = over.find((object) => object.getData('userId') !== undefined);

        if (clicked) {
            const userId = clicked.getData('userId') as number;

            if (userId !== this.selfId) {
                const avatar = this.avatars.get(userId);
                this.bus.emit('report-target', {
                    userId,
                    username: avatar?.username ?? '',
                    messageId: avatar?.lastMessage?.id ?? null,
                    body: avatar?.lastMessage?.body ?? null,
                });

                return;
            }
        }

        const tile = this.pointerTile(pointer);

        if (tile) void this.walkTo(tile);
    }

    private async walkTo(target: Tile): Promise<void> {
        const self = this.avatars.get(this.selfId);

        if (!self || !this.grid) return;

        const previous = self.state;
        const predicted = this.predict(previous, target);

        if (predicted) {
            self.state = predicted;
            const p = tileToScreen(target[0], target[1], this.originX);
            this.marker.setPosition(p.x, p.y).setVisible(true).setAlpha(1);
        }

        try {
            const confirmed = await this.net.move(target);

            // A dropped (rate-limited) move keeps whatever the server last confirmed.
            self.state = confirmed
                ? { path: confirmed.path, started_at_ms: confirmed.started_at_ms, speed: confirmed.speed }
                : previous;
        } catch (error) {
            self.state = previous;
            this.marker.setVisible(false);

            if (error instanceof RefusedError && error.reason === 'unreachable') {
                this.bus.emit('notice', { message: error.message });
            }
        }
    }

    /** Same rule as MovePlayer: finish the step under way, then follow A* from its end. */
    private predict(current: PathState, target: Tile): PathState | null {
        if (!this.grid) return null;

        const now = this.net.serverNow();
        const pos = positionAt(current.path, current.started_at_ms, current.speed, now);
        const midStep = !pos.finished && pos.progress > 0;
        const start = midStep ? pos.to : pos.tile;
        const route = findPath(this.grid, start, target, this.options.maxPathSteps);

        if (!route) return null;

        return {
            path: midStep ? [pos.from, ...route] : route,
            started_at_ms: midStep ? now - Math.round(pos.segmentElapsedMs) : now,
            speed: current.speed,
        };
    }

    // ── Map and avatars ───────────────────────────────────────────────

    private buildMap(key: string): void {
        this.layer?.destroy();
        const map = this.make.tilemap({ key });
        const tileset = map.addTilesetImage('placeholder', 'tiles');
        this.originX = map.height * (TILE_WIDTH / 2);

        if (tileset) {
            // Phaser draws an isometric tile with its image's top-left at the returned
            // world point, so shift by half a tile to put tile (0,0)'s top corner at originX.
            this.layer = map.createLayer('ground', tileset, this.originX - TILE_WIDTH / 2, 0);
            this.layer?.setDepth(0);
        }

        const walkable = map.getLayer('walkable');
        const cells = new Uint8Array(map.width * map.height);

        walkable?.data.forEach((row, y) =>
            row.forEach((tile, x) => {
                cells[y * map.width + x] = tile.index > 0 ? 1 : 0;
            }),
        );

        this.grid = { width: map.width, height: map.height, walkable: cells };
        this.mapKey = key;

        const widthPx = (map.width + map.height) * (TILE_WIDTH / 2);
        const heightPx = (map.width + map.height) * (TILE_HEIGHT / 2);
        this.cameras.main.setBounds(-200, -200, widthPx + 400, heightPx + 400);
    }

    private upsert(player: PlayerState): void {
        const state = { path: player.path, started_at_ms: player.started_at_ms, speed: player.speed };
        const existing = this.avatars.get(player.id);

        if (existing) {
            existing.state = state;
            this.tint(existing.body, player.avatar);

            return;
        }

        const body = this.add.image(0, 4, 'resident').setOrigin(0.5, 1);
        this.tint(body, player.avatar);
        body.setInteractive({ useHandCursor: player.id !== this.selfId });
        body.setData('userId', player.id);

        const label = this.add
            .text(0, -body.height - 2, player.username, {
                fontFamily: 'Instrument Sans, system-ui, sans-serif',
                fontSize: '12px',
                color: player.id === this.selfId ? '#ffe9a8' : '#f4efe4',
                stroke: '#1d2330',
                strokeThickness: 3,
            })
            .setOrigin(0.5, 1);

        const container = this.add.container(0, 0, [body, label]);

        this.avatars.set(player.id, {
            id: player.id,
            username: player.username,
            state,
            container,
            body,
            bubble: null,
            bubbleTween: null,
            lastMessage: null,
        });
    }

    private remove(id: number): void {
        const avatar = this.avatars.get(id);

        if (!avatar) return;

        avatar.bubbleTween?.remove();
        avatar.container.destroy(true);
        this.avatars.delete(id);
    }

    private tint(body: Phaser.GameObjects.Image, look: AvatarLook): void {
        body.setTint(Phaser.Display.Color.HexStringToColor(look?.body_color ?? '#cccccc').color);
    }

    private say(userId: number, messageId: number | null, body: string): void {
        const avatar = this.avatars.get(userId);

        if (!avatar) return;

        avatar.lastMessage = { id: messageId, body };
        avatar.bubbleTween?.remove();
        avatar.bubble?.destroy(true);

        const text = this.add
            .text(0, 0, body, {
                fontFamily: 'Instrument Sans, system-ui, sans-serif',
                fontSize: '13px',
                color: '#1d2330',
                wordWrap: { width: 180 },
                align: 'center',
            })
            .setOrigin(0.5, 1);
        const pad = 6;
        const bg = this.add
            .rectangle(0, pad, text.width + pad * 2, text.height + pad * 2, 0xfbf7ee)
            .setOrigin(0.5, 1)
            .setStrokeStyle(1, 0x1d2330, 0.4)
            .setInteractive();
        bg.setData('userId', userId);

        const bubble = this.add.container(0, -avatar.body.height - 20, [bg, text]);
        avatar.container.add(bubble);
        avatar.bubble = bubble;
        avatar.bubbleTween = this.tweens.add({
            targets: bubble,
            alpha: 0,
            delay: this.options.bubbleSeconds * 1000 - 600,
            duration: 600,
            onComplete: () => {
                bubble.destroy(true);

                if (avatar.bubble === bubble) avatar.bubble = null;
            },
        });
    }

    private pointerTile(pointer: Phaser.Input.Pointer): Tile | null {
        if (!this.grid) return null;

        const world = this.cameras.main.getWorldPoint(pointer.x, pointer.y);
        const tile = screenToTile(world.x, world.y, this.originX);

        return tile[0] >= 0 && tile[1] >= 0 && tile[0] < this.grid.width && tile[1] < this.grid.height
            ? tile
            : null;
    }
}
