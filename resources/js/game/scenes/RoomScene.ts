import Phaser from 'phaser';
import type { EventBus, FishingPhase } from '../bus';
import type { FishingView } from '../fishing';
import type { WorldListener } from '../net/WorldNet';
import type {
    AssetManifest,
    ChatMessageSentEvent,
    PlayerJoinedEvent,
    PlayerLeftEvent,
    PlacedFurniture,
    PlayerMovedEvent,
    Item,
    PlayerState,
    Snapshot,
    Tile,
} from '../protocol/types';
import { pathState } from '../protocol/types';
import type { Grid } from '../shared/astar';
import { isWalkable } from '../shared/astar';
import { withFurniture } from '../shared/furniture';
import { TILE_IMAGE_HEIGHT, TILE_WIDTH } from '../shared/iso';
import { positionOf } from '../shared/pathClock';
import { CAMERA, COLORS, DEPTH, TRAVEL } from '../theme';
import { Arrivals } from '../world/Arrivals';
import type { AssetFile } from '../world/AssetLoader';
import { AssetLoader } from '../world/AssetLoader';
import { AVATAR_TEXTURE, AvatarView } from '../world/AvatarView';
import { BuildingLayer, buildingTexture } from '../world/BuildingLayer';
import type { DecoratePort } from '../world/DecorateMode';
import { DecorateMode } from '../world/DecorateMode';
import { FishingLine, waterBeside } from '../world/FishingLine';
import {
    FurnitureLayer,
    itemTexture,
    mirroredTexture,
} from '../world/FurnitureLayer';
import { WallLayer, wallTexture } from '../world/WallLayer';
import type { MapBuilding } from '../world/IsoMap';
import { IsoMap, TILES_TEXTURE } from '../world/IsoMap';
import { KeyboardMovement } from '../world/KeyboardMovement';
import type { MovementPort } from '../world/MovementController';
import { MovementController } from '../world/MovementController';

/** The textures every room needs, loaded before anything is drawn. Each room's own art loads with it. */
function bootAssets(): AssetFile[] {
    return [
        {
            key: TILES_TEXTURE,
            url: (m) => m.images.tiles,
            spritesheet: {
                frameWidth: TILE_WIDTH,
                frameHeight: TILE_IMAGE_HEIGHT,
            },
        },
        { key: AVATAR_TEXTURE, url: (m) => m.images.resident },
        { key: 'marker', url: (m) => m.images.marker },
        { key: 'hover', url: (m) => m.images.hover },
    ];
}

/** The slice of the network layer the scene needs. */
export type RoomPort = MovementPort &
    DecoratePort & {
        /** Ends the game with a fatal screen, for failures the scene can't recover from. */
        abort(error: unknown): void;
        useExit(name: string): Promise<void>;
    };

export type RoomSceneOptions = {
    manifest: AssetManifest;
    /** Fetches the manifest again, for when an asset fails to load (see AssetLoader). */
    refetchManifest: () => Promise<AssetManifest>;
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
 *
 * The scene owns the room's lifecycle (snapshots, live events, travel) and
 * routes input; each feature lives in its own part: the map and its layers
 * (IsoMap, BuildingLayer, FurnitureLayer), walking (MovementController,
 * KeyboardMovement), what a walk ends on (Arrivals), decorating
 * (DecorateMode) and the fishing line (FishingLine).
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
    /** Where players may walk: the map, less the tiles under furniture in a home. */
    private walkGrid: Grid | null = null;
    private buildings: BuildingLayer | null = null;
    private walls: WallLayer | null = null;
    private readonly decorate: DecorateMode;
    private readonly arrivals: Arrivals;
    private readonly assets: AssetLoader;
    /** Texture keys of the art the current room loaded. */
    private roomArt = new Set<string>();
    /** The arrangement drawn last, for redrawing once late art arrives. */
    private placed: PlacedFurniture[] = [];
    /** Between leaving one room and drawing the next: the screen is faded and taps are ignored. */
    private travelling = false;
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
            grid: () => this.walkGrid,
            showMarker: (tile) => this.showMarker(tile),
            hideMarker: () => this.marker.setVisible(false),
            notice: (message) => this.bus.emit('notice', { message }),
        });
        this.decorate = new DecorateMode(port, options.refused);
        this.assets = new AssetLoader(
            this,
            options.manifest,
            options.refetchManifest,
        );
        this.arrivals = new Arrivals({
            door: (door) => this.travel(() => this.port.useExit(door.name)),
            fixture: ({ kind }) => this.bus.emit('use-fixture', { kind }),
        });
    }

    create(): void {
        this.cameras.main.setBackgroundColor(COLORS.night);
        void this.assets.load(bootAssets()).then(() => this.start());
    }

    /** Sets the room up once the textures every room needs are in. */
    private start(): void {
        this.hover = this.add
            .image(0, 0, 'hover')
            .setVisible(false)
            .setDepth(DEPTH.hover);
        this.marker = this.add
            .image(0, 0, 'marker')
            .setVisible(false)
            .setDepth(DEPTH.marker);
        this.decorate.create(this);

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
            grid: () => this.walkGrid,
            blocked: () => this.travelling || this.decorate.on,
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
        let selfAt: { x: number; y: number } | null = null;

        for (const avatar of this.avatars.values()) {
            const pos = avatar.update(now, delta, this.map);
            positions.push(pos);

            if (avatar.id !== this.selfId) continue;

            selfAt = pos;

            // Walking off with a line in the water abandons it.
            if (!pos.finished && this.fishingLine?.active) {
                this.fishingLine.reset();
                this.bus.emit('fishing-interrupted', {});
            }

            if (!pos.finished) continue;

            if (this.marker.visible) this.marker.setVisible(false);

            this.arrivals.arrived(this.map, avatar.state, pos.tile);
        }

        this.map.hideLabelsNear(positions);

        const self = this.avatars.get(this.selfId);
        const seen =
            self && selfAt
                ? { at: selfAt, box: self.bounds, depthY: self.target.y }
                : null;
        this.buildings?.update(seen, delta);
        this.furniture?.update(seen, delta);

        this.fishingLine?.update(time, self?.target ?? null);
    }

    /** Game → scene: where the player's line is. The bobber lands on the water beside them. */
    showFishing(phase: FishingPhase, landed?: boolean): void {
        const self = this.avatars.get(this.selfId);
        const water =
            self && this.map
                ? waterBeside(
                      this.map,
                      positionOf(self.state, this.port.serverNow()).tile,
                  )
                : null;
        this.fishingLine?.set(phase, this.time.now, water, landed);
    }

    /**
     * Fades out, runs the request that moves the player, and fades back in once
     * the next snapshot is drawn (or straight away when the request fails).
     */
    travel(run: () => Promise<void>): void {
        if (this.travelling) return;

        this.travelling = true;
        this.decorate.set(false, null);

        // Before start() there is no marker yet, and nothing to hide.
        if (this.ready) this.marker.setVisible(false);
        this.cameras.main.fadeOut(TRAVEL.fadeMs, ...NIGHT_RGB);

        run().catch((error: unknown) => {
            this.arrived();
            this.options.refused(error);
        });
    }

    /** UI → game: decorate mode is only honoured in the player's own home. */
    setDecorating(active: boolean, armed: Item | null): void {
        this.decorate.set(active, armed);
    }

    /** UI → game: a placed piece turns a quarter turn where it stands. The server says if it fits. */
    turnFurniture(itemId: number): void {
        this.decorate.turn(itemId);
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
            this.walls?.destroy();
            this.furniture = this.buildings = this.walls = null;
            this.map?.destroy();
            this.fishingLine?.reset();
            this.map = new IsoMap(this, key);
        }

        // The room's own art loads with it, before its layers are drawn.
        const files = this.roomFiles(this.map, snapshot.furniture);

        if (this.assets.missing(files).length > 0) {
            this.loadRoomArt(files, snapshot);

            return;
        }

        if (!this.furniture) {
            this.dropArtExcept(files);
            this.furniture = new FurnitureLayer(this, this.map);
            this.buildings = new BuildingLayer(this, this.map);
            this.walls = new WallLayer(this, this.map);
        }

        this.setFurniture(snapshot.furniture);
        this.decorate.enter(snapshot.home?.owner_id === selfId);

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
        this.whenDrawable(() => this.setFurniture(placed));
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

        this.decorate.hover(this.map, tile);

        const show =
            !building &&
            tile !== null &&
            this.walkGrid !== null &&
            (this.decorate.on || isWalkable(this.walkGrid, tile[0], tile[1]));
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

        if (this.decorate.on) {
            this.decorate.tap(this.pointerTile(pointer));

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

    /**
     * Draws a home's arrangement, and walls its blocking pieces off from
     * walking. A piece whose art isn't loaded yet is drawn as a placeholder
     * and redrawn once its art is in.
     */
    private setFurniture(placed: PlacedFurniture[]): void {
        this.placed = placed;
        this.decorate.arrange(placed);
        this.furniture?.set(placed);
        this.walkGrid = this.map ? withFurniture(this.map.grid, placed) : null;

        const files = this.itemFiles(placed);

        if (this.assets.missing(files).length === 0) return;

        files.forEach((file) => this.roomArt.add(file.key));
        void this.assets.load(files).then(() =>
            this.whenDrawable(() => {
                // Whatever stands there by now, which may have changed meanwhile.
                if (this.map && this.furniture) this.setFurniture(this.placed);
            }),
        );
    }

    /** The art a room draws: its buildings, its wall style, and the furniture placed in it. */
    private roomFiles(map: IsoMap, placed: PlacedFurniture[]): AssetFile[] {
        const { manifest } = this.assets;
        const walls = map.walls
            ? Object.keys(manifest.walls[map.walls] ?? {}).map((part) => {
                  const style = map.walls as string;

                  return {
                      key: wallTexture(`${style}/${part}`),
                      url: (m: AssetManifest) => m.walls[style]?.[part],
                  };
              })
            : [];

        return [
            ...map.buildings.map(({ name }) => ({
                key: buildingTexture(name),
                url: (m: AssetManifest) => m.buildings[name],
            })),
            ...walls,
            ...this.itemFiles(placed),
        ];
    }

    private itemFiles(placed: PlacedFurniture[]): AssetFile[] {
        const slugs = new Set(placed.map((piece) => piece.item.slug));

        return [...slugs].flatMap((slug) =>
            Object.keys(this.assets.manifest.items[slug] ?? {}).map((part) => ({
                key: itemTexture(`${slug}/${part}`),
                url: (m: AssetManifest) => m.items[slug]?.[part],
            })),
        );
    }

    /** Waits for a room's art like a map: the newest snapshot is drawn when it is in. */
    private loadRoomArt(files: AssetFile[], snapshot: Snapshot): void {
        this.loadingMap = true;
        this.pendingSnapshot = snapshot;
        void this.assets.load(files).then(() => {
            this.loadingMap = false;
            const pending = this.pendingSnapshot;
            this.pendingSnapshot = null;

            if (pending) this.snapshot(pending, this.selfId);
        });
    }

    /**
     * Frees the art of the room just left, flipped copies included, unless the
     * room being drawn uses it too. Textures cost 4 bytes a pixel on the GPU.
     */
    private dropArtExcept(files: AssetFile[]): void {
        const keep = new Set(files.map((file) => file.key));

        for (const key of this.roomArt) {
            if (keep.has(key)) continue;

            for (const texture of [key, mirroredTexture(key)]) {
                if (this.textures.exists(texture))
                    this.textures.remove(texture);
            }

            this.assets.forget(key);
        }

        this.roomArt = keep;
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
