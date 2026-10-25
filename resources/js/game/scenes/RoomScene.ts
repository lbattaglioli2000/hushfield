import Phaser from 'phaser';
import type { EventBus } from '../bus';
import type { WorldListener } from '../net/WorldNet';
import type {
    AssetManifest,
    ChatMessageSentEvent,
    PlayerJoinedEvent,
    PlayerLeftEvent,
    PathState,
    PlacedFurniture,
    PlayerMovedEvent,
    PlayerState,
    Snapshot,
    Tile,
} from '../protocol/types';
import { pathState } from '../protocol/types';
import { TILE_HEIGHT, TILE_WIDTH } from '../shared/iso';
import { CAMERA, COLORS, DEPTH, TRAVEL } from '../theme';
import { AVATAR_TEXTURE, AvatarView } from '../world/AvatarView';
import { FurnitureLayer } from '../world/FurnitureLayer';
import { IsoMap, TILES_TEXTURE } from '../world/IsoMap';
import { KeyboardMovement } from '../world/KeyboardMovement';
import type { MovementPort } from '../world/MovementController';
import { MovementController } from '../world/MovementController';

/** The slice of the network layer the scene needs. */
export type RoomPort = MovementPort & {
    /** Ends the game with a fatal screen, for failures the scene can't recover from. */
    abort(error: unknown): void;
    useExit(name: string): Promise<void>;
    placeFurniture(itemId: number, tile: Tile): Promise<void>;
    putAwayFurniture(itemId: number): Promise<void>;
};

export type RoomSceneOptions = {
    manifest: AssetManifest;
    /** Tells the player why something they asked for (a door, a piece of furniture) didn't happen. */
    refused: (error: unknown) => void;
    bubbleSeconds: number;
    maxPathSteps: number;
};

const TAP_SLOP_PX = 10;

const NIGHT_RGB = (() => {
    const { red, green, blue } = Phaser.Display.Color.HexStringToColor(
        COLORS.night,
    );

    return [red, green, blue] as const;
})();

/**
 * Draws whichever room the latest snapshot describes, map included. Every position
 * is evaluated from server-confirmed paths through PathClock each frame; the scene
 * never invents positions of its own beyond the player's own predicted walk.
 */
export class RoomScene extends Phaser.Scene implements WorldListener {
    static readonly KEY = 'room';

    private readonly avatars = new Map<number, AvatarView>();
    private readonly movement: MovementController;
    private keyboard: KeyboardMovement | null = null;
    private selfId = 0;
    private map: IsoMap | null = null;
    private furniture: FurnitureLayer | null = null;
    /** Whether the drawn instance is the player's own home. */
    private ownHome = false;
    private decorating: { armedItemId: number | null } | null = null;
    /** Between leaving one room and drawing the next: the screen is faded and taps are ignored. */
    private travelling = false;
    /** The path whose arrival at a door was already acted on, so each walk goes through at most once. */
    private doorTriedFor: PathState | null = null;
    /** Likewise for fixtures: each walk onto one uses it once. */
    private fixtureUsedFor: PathState | null = null;
    private hover!: Phaser.GameObjects.Image;
    private marker!: Phaser.GameObjects.Image;
    /** A snapshot that arrived before create(), or while its map was loading. */
    private pendingSnapshot: Snapshot | null = null;
    private ready = false;
    private loadingMap = false;
    /** Live events that arrived after the latest snapshot but before it could be drawn. */
    private queued: Array<() => void> = [];

    constructor(
        private readonly port: RoomPort,
        private readonly bus: EventBus,
        private readonly options: RoomSceneOptions,
    ) {
        super(RoomScene.KEY);
        this.movement = new MovementController({
            port,
            maxPathSteps: options.maxPathSteps,
            self: () => this.avatars.get(this.selfId),
            grid: () => this.map?.grid ?? null,
            showMarker: (tile) => this.showMarker(tile),
            hideMarker: () => this.marker.setVisible(false),
            notice: (message) => this.bus.emit('notice', { message }),
        });
    }

    preload(): void {
        const { images } = this.options.manifest;
        this.load.spritesheet(TILES_TEXTURE, images.tiles, {
            frameWidth: TILE_WIDTH,
            frameHeight: TILE_HEIGHT,
        });
        this.load.image(AVATAR_TEXTURE, images.resident);
        this.load.image('marker', images.marker);
        this.load.image('hover', images.hover);
    }

    create(): void {
        this.cameras.main.setBackgroundColor(COLORS.night);
        this.hover = this.add
            .image(0, 0, 'hover')
            .setVisible(false)
            .setDepth(DEPTH.hover);
        this.marker = this.add
            .image(0, 0, 'marker')
            .setVisible(false)
            .setDepth(DEPTH.marker);

        this.input.on('pointermove', (pointer: Phaser.Input.Pointer) =>
            this.onHover(pointer),
        );
        this.input.on(
            'pointerup',
            (
                pointer: Phaser.Input.Pointer,
                over: Phaser.GameObjects.GameObject[],
            ) => this.onTap(pointer, over),
        );

        this.keyboard = new KeyboardMovement({
            movement: this.movement,
            maxPathSteps: this.options.maxPathSteps,
            grid: () => this.map?.grid ?? null,
            blocked: () =>
                this.travelling || (this.decorating !== null && this.ownHome),
            activity: () => this.bus.emit('input-activity', {}),
        });
        this.events.once(Phaser.Scenes.Events.DESTROY, () =>
            this.keyboard?.destroy(),
        );

        this.ready = true;

        if (this.pendingSnapshot) {
            const pending = this.pendingSnapshot;
            this.pendingSnapshot = null;
            this.snapshot(pending, this.selfId);
        } else {
            this.drainQueue();
        }
    }

    update(time: number, delta: number): void {
        if (!this.map) return;

        this.keyboard?.update(time);

        const now = this.port.serverNow();

        for (const avatar of this.avatars.values()) {
            const pos = avatar.update(now, delta, this.map);

            if (avatar.id !== this.selfId || !pos.finished) continue;

            if (this.marker.visible) this.marker.setVisible(false);

            const door = this.map.doorAt(pos.tile);

            if (door && this.doorTriedFor !== avatar.state) {
                this.doorTriedFor = avatar.state;
                this.travel(() => this.port.useExit(door.name));
            }

            const fixture = this.map.fixtureAt(pos.tile);

            if (fixture && this.fixtureUsedFor !== avatar.state) {
                this.fixtureUsedFor = avatar.state;
                this.bus.emit('use-fixture', { kind: fixture.kind });
            }
        }
    }

    /**
     * Fades out, runs the request that moves the player, and fades back in once
     * the next snapshot is drawn (or straight away when the request fails).
     */
    travel(run: () => Promise<void>): void {
        if (this.travelling) return;

        this.travelling = true;
        this.decorating = null;
        this.marker.setVisible(false);
        this.cameras.main.fadeOut(TRAVEL.fadeMs, ...NIGHT_RGB);

        run().catch((error: unknown) => {
            this.arrived();
            this.options.refused(error);
        });
    }

    /** UI → game: decorate mode is only honoured in the player's own home. */
    setDecorating(active: boolean, armedItemId: number | null): void {
        this.decorating = active ? { armedItemId } : null;
    }

    // ── WorldListener ─────────────────────────────────────────────────

    snapshot(snapshot: Snapshot, selfId: number): void {
        this.selfId = selfId;
        // The snapshot supersedes anything that happened before it.
        this.queued = [];

        if (!this.ready || this.loadingMap) {
            this.pendingSnapshot = snapshot;

            return;
        }

        const key = `map:${snapshot.room.slug}:${snapshot.room.map_version}`;

        if (this.map?.key !== key) {
            if (!this.cache.tilemap.exists(key)) {
                this.loadMap(key, snapshot);

                return;
            }

            this.furniture?.destroy();
            this.map?.destroy();
            this.map = new IsoMap(this, key);
            this.furniture = new FurnitureLayer(this, this.map);
        }

        this.furniture?.set(snapshot.furniture);
        this.ownHome = snapshot.home?.owner_id === selfId;

        if (!this.ownHome) this.decorating = null;

        // Replace local state wholesale: the snapshot is the truth.
        const seen = new Set<number>();

        for (const player of snapshot.players) {
            seen.add(player.id);
            this.upsert(player);
        }

        // Deleting the current key while iterating a Map is safe.
        for (const id of this.avatars.keys()) {
            if (!seen.has(id)) this.remove(id);
        }

        const self = this.avatars.get(selfId);

        if (self) {
            this.cameras.main.startFollow(
                self.target,
                true,
                CAMERA.followLerp,
                CAMERA.followLerp,
            );
        }

        this.drainQueue();

        if (this.travelling) this.arrived();
    }

    joined(event: PlayerJoinedEvent): void {
        this.whenDrawable(() => this.upsert(event.player));
    }

    left(event: PlayerLeftEvent): void {
        this.whenDrawable(() => {
            if (event.user_id !== this.selfId) this.remove(event.user_id);
        });
    }

    moved(event: PlayerMovedEvent): void {
        this.whenDrawable(() =>
            this.avatars
                .get(event.user_id)
                ?.setState(pathState(event), this.port.serverNow()),
        );
    }

    chat(event: ChatMessageSentEvent): void {
        this.whenDrawable(() => this.say(event.user_id, event.id, event.body));
    }

    idle(userId: number, idle: boolean): void {
        this.whenDrawable(() => this.avatars.get(userId)?.setIdle(idle));
    }

    furnitureChanged(placed: PlacedFurniture[]): void {
        this.whenDrawable(() => this.furniture?.set(placed));
    }

    /** Shows the player's own line straight away, including when muted (only they see it then). */
    sayOwn(id: number | null, body: string): void {
        this.say(this.selfId, id, body);
    }

    // ── Input ─────────────────────────────────────────────────────────

    private onHover(pointer: Phaser.Input.Pointer): void {
        const tile = this.pointerTile(pointer);
        const show =
            tile !== null && this.map !== null && this.map.isWalkable(tile);
        this.hover.setVisible(show && !pointer.wasTouch);

        if (show && tile && this.map) {
            const p = this.map.toWorld(tile[0], tile[1]);
            this.hover.setPosition(p.x, p.y);
        }
    }

    private onTap(
        pointer: Phaser.Input.Pointer,
        over: Phaser.GameObjects.GameObject[],
    ): void {
        if (
            Phaser.Math.Distance.Between(
                pointer.downX,
                pointer.downY,
                pointer.upX,
                pointer.upY,
            ) > TAP_SLOP_PX
        ) {
            return;
        }

        if (this.travelling) return;

        this.bus.emit('input-activity', {});

        if (this.decorating && this.ownHome) {
            this.decorateAt(this.pointerTile(pointer));

            return;
        }

        const clicked = over.find(
            (object) => object.getData('userId') !== undefined,
        );

        if (clicked) {
            const userId = clicked.getData('userId') as number;

            if (userId !== this.selfId) {
                const avatar = this.avatars.get(userId);
                this.bus.emit('player-selected', {
                    userId,
                    username: avatar?.username ?? '',
                    messageId: avatar?.lastMessage?.id ?? null,
                    body: avatar?.lastMessage?.body ?? null,
                });

                return;
            }
        }

        const tile = this.pointerTile(pointer);
        const fixture = tile && this.map?.fixtureAt(tile);

        // Tapping a fixture uses it straight away; the player still walks up to it.
        if (fixture) this.bus.emit('use-fixture', { kind: fixture.kind });

        if (tile) void this.movement.walkTo(tile);
    }

    /** A tap while decorating: put away the piece on the tile, or place the armed one there. */
    private decorateAt(tile: Tile | null): void {
        if (!tile || !this.decorating) return;

        const placed = this.furniture?.at(tile);
        const request = placed
            ? this.port.putAwayFurniture(placed.item.id)
            : this.decorating.armedItemId !== null
              ? this.port.placeFurniture(this.decorating.armedItemId, tile)
              : null;

        request?.catch((error: unknown) => this.options.refused(error));
    }

    private arrived(): void {
        this.travelling = false;
        this.cameras.main.fadeIn(TRAVEL.fadeMs, ...NIGHT_RGB);
    }

    private pointerTile(pointer: Phaser.Input.Pointer): Tile | null {
        if (!this.map) return null;

        const world = this.cameras.main.getWorldPoint(pointer.x, pointer.y);

        return this.map.tileAt(world.x, world.y);
    }

    private showMarker(tile: Tile): void {
        if (!this.map) return;

        const p = this.map.toWorld(tile[0], tile[1]);
        this.marker.setPosition(p.x, p.y).setVisible(true).setAlpha(1);
    }

    // ── Map and avatars ───────────────────────────────────────────────

    /** Live events wait while there is no snapshot drawn yet, or while its map is loading. */
    private whenDrawable(apply: () => void): void {
        if (this.ready && !this.loadingMap && !this.pendingSnapshot) {
            apply();
        } else {
            this.queued.push(apply);
        }
    }

    private drainQueue(): void {
        const queued = this.queued;
        this.queued = [];
        queued.forEach((apply) => apply());
    }

    /** Loads a room map once; the newest snapshot that arrives meanwhile is drawn when it completes. */
    private loadMap(key: string, snapshot: Snapshot): void {
        this.loadingMap = true;
        this.pendingSnapshot = snapshot;
        this.load.tilemapTiledJSON(key, snapshot.room.map_url);
        this.load.once(Phaser.Loader.Events.COMPLETE, () => {
            this.loadingMap = false;

            // Phaser emits COMPLETE even when a file failed; retrying would loop forever.
            if (!this.cache.tilemap.exists(key)) {
                this.pendingSnapshot = null;
                this.queued = [];
                this.port.abort(new Error(`Could not load the map ${key}.`));

                return;
            }

            const pending = this.pendingSnapshot;
            this.pendingSnapshot = null;

            if (pending) this.snapshot(pending, this.selfId);
        });
        this.load.start();
    }

    private upsert(player: PlayerState): void {
        const isSelf = player.id === this.selfId;
        const existing = this.avatars.get(player.id);

        if (isSelf) this.movement.confirm(pathState(player));

        if (existing) {
            existing.setState(pathState(player), this.port.serverNow());
            existing.setLook(player.avatar);

            return;
        }

        this.avatars.set(player.id, new AvatarView(this, player, isSelf));
    }

    private remove(id: number): void {
        this.avatars.get(id)?.destroy();
        this.avatars.delete(id);
    }

    private say(userId: number, messageId: number | null, body: string): void {
        this.avatars
            .get(userId)
            ?.say(messageId, body, this.options.bubbleSeconds);
    }
}
