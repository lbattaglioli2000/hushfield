import Phaser from 'phaser';
import type { EventBus, FishingPhase } from '../bus';
import type { FishingView } from '../fishing';
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
import { TILE_IMAGE_HEIGHT, TILE_WIDTH } from '../shared/iso';
import { positionOf } from '../shared/pathClock';
import { CAMERA, COLORS, DEPTH, FISHING, TRAVEL } from '../theme';
import { AVATAR_TEXTURE, AvatarView } from '../world/AvatarView';
import { BuildingLayer, buildingTexture } from '../world/BuildingLayer';
import { FishingLine } from '../world/FishingLine';
import { FurnitureLayer } from '../world/FurnitureLayer';
import type { MapBuilding } from '../world/IsoMap';
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
export class RoomScene
    extends Phaser.Scene
    implements WorldListener, FishingView
{
    static readonly KEY = 'room';

    private readonly avatars = new Map<number, AvatarView>();
    private readonly movement: MovementController;
    private keyboard: KeyboardMovement | null = null;
    private selfId = 0;
    private map: IsoMap | null = null;
    private furniture: FurnitureLayer | null = null;
    private buildings: BuildingLayer | null = null;
    /** Whether the drawn instance is the player's own home. */
    private ownHome = false;
    private decorating: { armedItemId: number | null } | null = null;
    /** Between leaving one room and drawing the next: the screen is faded and taps are ignored. */
    private travelling = false;
    /** The path whose arrival at a door was already acted on, so each walk goes through at most once. */
    private doorTriedFor: PathState | null = null;
    /** Likewise for fixtures: each walk onto one uses it once. */
    private fixtureUsedFor: PathState | null = null;
    private fishingLine: FishingLine | null = null;
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
            frameHeight: TILE_IMAGE_HEIGHT,
        });
        this.load.image(AVATAR_TEXTURE, images.resident);
        this.load.image('marker', images.marker);
        this.load.image('hover', images.hover);

        for (const [slug, url] of Object.entries(
            this.options.manifest.buildings,
        )) {
            this.load.image(buildingTexture(slug), url);
        }
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

        this.fishingLine = new FishingLine(this);

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
        const fitCamera = () => this.map?.fitCamera(this.cameras.main);
        this.scale.on(Phaser.Scale.Events.RESIZE, fitCamera);
        this.events.once(Phaser.Scenes.Events.DESTROY, () => {
            this.keyboard?.destroy();
            this.scale.off(Phaser.Scale.Events.RESIZE, fitCamera);
        });

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
        const positions: Array<{ x: number; y: number }> = [];

        for (const avatar of this.avatars.values()) {
            const pos = avatar.update(now, delta, this.map);
            positions.push(pos);

            if (avatar.id !== this.selfId) continue;

            // Walking off with a line in the water abandons it.
            if (!pos.finished && this.fishingLine?.active) {
                this.fishingLine.reset();
                this.bus.emit('fishing-interrupted', {});
            }

            if (!pos.finished) continue;

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

        this.map.hideLabelsNear(positions);

        const self = this.avatars.get(this.selfId);
        this.buildings?.update(
            self ? { box: self.bounds, depthY: self.target.y } : null,
            delta,
        );

        this.fishingLine?.update(time, self?.target ?? null);
    }

    /** Game → scene: where the player's line is. The bobber lands on the water beside them. */
    showFishing(phase: FishingPhase, landed?: boolean): void {
        this.fishingLine?.set(phase, this.time.now, this.waterBeside(), landed);
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
            this.buildings?.destroy();
            this.map?.destroy();
            this.fishingLine?.reset();
            this.map = new IsoMap(this, key);
            this.furniture = new FurnitureLayer(this, this.map);
            this.buildings = new BuildingLayer(this, this.map);
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
        // Over a building or its door, name the building instead of highlighting a tile.
        const building =
            this.pointerBuilding(pointer) ??
            (tile && this.map?.buildingAt(tile)) ??
            null;
        this.buildings?.hover(pointer.wasTouch ? null : building);
        this.input.setDefaultCursor(building ? 'pointer' : 'default');

        const show =
            !building &&
            tile !== null &&
            this.map !== null &&
            this.map.isWalkable(tile);
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

        // Tapping a building walks the player in through its door.
        const building = this.pointerBuilding(pointer);

        if (building) {
            void this.movement.walkTo(building.door.tile);

            return;
        }

        // A fixture is used on arrival (see update()), so tapping one just walks there.
        const tile = this.pointerTile(pointer);

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

    private pointerBuilding(pointer: Phaser.Input.Pointer): MapBuilding | null {
        const world = this.cameras.main.getWorldPoint(pointer.x, pointer.y);

        return this.buildings?.at(world.x, world.y) ?? null;
    }

    /**
     * A point out over the water from the fishing spot nearest the player: past
     * the spot's first unwalkable neighbouring tile, preferring the far side.
     */
    private waterBeside(): { x: number; y: number } | null {
        const self = this.avatars.get(this.selfId);

        if (!self || !this.map) return null;

        const [px, py] = positionOf(self.state, this.port.serverNow()).tile;
        const distance = ([x, y]: Tile) =>
            Math.max(Math.abs(x - px), Math.abs(y - py));
        const spot = this.map.fixtures
            .filter((fixture) => fixture.kind === 'fishing')
            .map((fixture) => fixture.tile)
            .sort((a, b) => distance(a) - distance(b))[0] ?? [px, py];
        const [x, y] = spot;
        const map = this.map;
        const [dx, dy] = (
            [
                [0, 1],
                [1, 0],
                [-1, 0],
                [0, -1],
            ] as const
        ).find(([dx, dy]) => !map.isWalkable([x + dx, y + dy])) ?? [0, 1];

        return map.toWorld(
            x + dx * FISHING.reachTiles,
            y + dy * FISHING.reachTiles,
        );
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
