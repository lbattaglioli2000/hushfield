import type { EventBus, FatalKind } from '../bus';
import type { Copy } from '../copy';
import {
    ROOM_EVENTS,
    SESSION_EVENTS,
    sessionChannel,
    WHISPERS,
} from '../protocol/channels';
import type {
    ChatMessageSentEvent,
    FishingCast,
    FriendsList,
    FriendsUpdate,
    FriendsUpdatedEvent,
    HomeVisitors,
    FurnitureChangedEvent,
    HomesList,
    IdleWhisper,
    InstanceReassignedEvent,
    Item,
    PathState,
    PlayerJoinedEvent,
    PlayerLeftEvent,
    PlayerMovedEvent,
    PlacedFurniture,
    RareCatchLandedEvent,
    ReelResult,
    Snapshot,
    Tile,
    TownMap,
    Wallet,
} from '../protocol/types';
import { pathState, PROTOCOL } from '../protocol/types';
import { isTransient, retryWhileTransient } from './backoff';
import { ServerClock } from './clock';
import { Http, RefusedError } from './http';
import type { RealtimeConfig } from './realtime';
import { Realtime } from './realtime';

export type NetConfig = {
    baseUrl: string;
    roomSlug: string;
    heartbeatSeconds: number;
    realtime: RealtimeConfig;
    copy: Copy;
};

/** The answer to anything that changes a wallet. */
export type WalletUpdate = { message: string; wallet: Wallet };

/** What the scene implements so the network layer can drive it. */
export type WorldListener = {
    snapshot(snapshot: Snapshot, selfId: number): void;
    joined(event: PlayerJoinedEvent): void;
    left(event: PlayerLeftEvent): void;
    moved(event: PlayerMovedEvent): void;
    chat(event: ChatMessageSentEvent): void;
    idle(userId: number, idle: boolean): void;
    furnitureChanged(placed: PlacedFurniture[]): void;
};

type JoinBody = { notice: string | null; snapshot: Snapshot };

/**
 * The world session: owns the session token and the current room instance,
 * joins and resyncs rooms, and sends heartbeats. HTTP, the socket and the clock
 * are delegated to Http, Realtime and ServerClock.
 */
export class WorldNet {
    private readonly http: Http;
    private readonly clock = new ServerClock();
    private realtime: Realtime | null = null;
    private token: string | null = null;
    private selfId = 0;
    private roomSlug: string;
    private instance: Snapshot['instance'] | null = null;
    private heartbeat: ReturnType<typeof setInterval> | null = null;
    private destroyed = false;
    private resyncing: Promise<void> | null = null;
    /** The manifest version this tab loaded its art from; null until the manifest is in. */
    assetsVersion: string | null = null;

    constructor(
        private readonly config: NetConfig,
        private readonly bus: EventBus,
        private readonly listener: WorldListener,
    ) {
        this.roomSlug = config.roomSlug;
        this.http = new Http(config.baseUrl, () => {
            const headers: Record<string, string> = {};
            const socketId = this.realtime?.socketId();

            if (this.token) headers['X-World-Session'] = this.token;
            if (socketId) headers['X-Socket-ID'] = socketId;

            return headers;
        });
    }

    /** Server time in epoch milliseconds, as best this client can tell. */
    serverNow(): number {
        return this.clock.now();
    }

    async start(): Promise<void> {
        this.bus.emit('connection', {
            state: 'connecting',
            message: this.config.copy.entering,
        });

        try {
            // The socket connects first so startSession() can subscribe to the session channel.
            this.connectSocket();

            // destroy() may run while any of these awaits are pending (React's dev double-mount does exactly that).
            await this.retrying(() => this.startSession());

            if (this.destroyed) return;

            await this.retrying(() => this.join());

            if (this.destroyed) return;

            this.startHeartbeat();
            window.addEventListener('pagehide', this.onPageHide);
        } catch (error) {
            this.abort(error);
        }
    }

    /** Moves this player to another room; the scene redraws from the snapshot that follows. */
    joinRoom(slug: string, instanceId?: number | null): Promise<void> {
        return this.join(instanceId, slug);
    }

    /** Goes through a door on the current map into the room behind it. */
    useExit(name: string): Promise<void> {
        return this.enter(
            this.instancePath(`exits/${encodeURIComponent(name)}`),
        );
    }

    /** Ends the game with a fatal screen: banned and moved sessions keep their kind, anything else is 'unavailable'. */
    abort(error: unknown): void {
        if (this.destroyed) return;

        if (error instanceof RefusedError) {
            if (error.reason === 'banned' || error.reason === 'session_moved') {
                this.fail(error.reason, error.message);
            } else {
                this.fail('unavailable', error.message);
            }

            return;
        }

        this.fail('unavailable', this.config.copy.please_reload);
    }

    async move(tile: Tile): Promise<PathState | null> {
        const body = await this.request<PathState | { dropped: true }>(
            'POST',
            this.instancePath('move'),
            { x: tile[0], y: tile[1] },
        );

        return 'dropped' in body ? null : pathState(body);
    }

    async chat(text: string): Promise<{ id: number; body: string } | null> {
        const body = await this.request<
            { id: number; body: string } | { dropped: true }
        >('POST', this.instancePath('chat'), { body: text });

        return 'dropped' in body ? null : body;
    }

    async report(
        userId: number,
        messageId: number | null,
        reason: string,
    ): Promise<string> {
        const body = await this.request<{ message: string }>(
            'POST',
            '/world/reports',
            {
                reported_user_id: userId,
                chat_message_id: messageId,
                room_instance_id: this.currentInstance().id,
                reason,
            },
        );

        return body.message;
    }

    async wallet(): Promise<Wallet> {
        const body = await this.request<{ wallet: Wallet }>(
            'GET',
            '/world/wallet',
        );

        return body.wallet;
    }

    /** The shelves, and whether anything can be bought (false while staff have the shop switched off). */
    shop(): Promise<{ items: Item[]; open: boolean }> {
        return this.request<{ items: Item[]; open: boolean }>(
            'GET',
            '/world/shop',
        );
    }

    purchase(itemId: number): Promise<WalletUpdate> {
        return this.request<WalletUpdate>(
            'POST',
            `/world/shop/${itemId}/purchase`,
        );
    }

    claimStipend(): Promise<WalletUpdate> {
        return this.request<WalletUpdate>('POST', '/world/stipend');
    }

    async castLine(): Promise<FishingCast> {
        return (
            await this.request<{ cast: FishingCast }>(
                'POST',
                '/world/fishing/cast',
            )
        ).cast;
    }

    reelIn(castId: string): Promise<WalletUpdate & { reel: ReelResult }> {
        return this.request('POST', '/world/fishing/reel', {
            cast_id: castId,
        });
    }

    town(): Promise<TownMap> {
        return this.request<TownMap>('GET', '/world/town');
    }

    homes(): Promise<HomesList> {
        return this.request<HomesList>('GET', '/world/homes');
    }

    async setHomeVisitors(visitors: HomeVisitors): Promise<string> {
        const body = await this.request<{ message: string }>(
            'POST',
            '/world/home/visitors',
            { visitors },
        );

        return body.message;
    }

    async friends(): Promise<FriendsList> {
        return (await this.request<FriendsUpdate>('GET', '/world/friends'))
            .friends;
    }

    sendFriendRequest(username: string): Promise<FriendsUpdate> {
        return this.request('POST', '/world/friends/requests', { username });
    }

    answerFriendRequest(
        requestId: number,
        accept: boolean,
    ): Promise<FriendsUpdate> {
        return accept
            ? this.request(
                  'POST',
                  `/world/friends/requests/${requestId}/accept`,
              )
            : this.request('DELETE', `/world/friends/requests/${requestId}`);
    }

    removeFriend(userId: number): Promise<FriendsUpdate> {
        return this.request('DELETE', `/world/friends/${userId}`);
    }

    setFriendRequests(allowed: boolean): Promise<FriendsUpdate> {
        return this.request('POST', '/world/friends/settings', {
            requests_allowed: allowed,
        });
    }

    /** Places, moves or turns a piece. Without a rotation, a placed piece keeps its turn. */
    async placeFurniture(
        itemId: number,
        tile: Tile,
        rotation?: number,
    ): Promise<void> {
        const body = await this.request<{ furniture: PlacedFurniture[] }>(
            'POST',
            `/world/home/furniture/${itemId}`,
            { x: tile[0], y: tile[1], rotation },
        );
        this.showFurniture(body.furniture);
    }

    async putAwayFurniture(itemId: number): Promise<void> {
        const body = await this.request<{ furniture: PlacedFurniture[] }>(
            'DELETE',
            `/world/home/furniture/${itemId}`,
        );
        this.showFurniture(body.furniture);
    }

    /** Cosmetic only: tells others to dim this avatar. Nothing on the server changes. */
    whisperIdle(idle: boolean): void {
        if (!this.instance || !this.realtime) return;

        const whisper: IdleWhisper = { user_id: this.selfId, idle };

        this.realtime.echo
            .join(this.instance.channel)
            .whisper(WHISPERS.idle, whisper);
    }

    /** Safe to call more than once. */
    destroy(): void {
        if (this.destroyed) return;

        this.destroyed = true;
        window.removeEventListener('pagehide', this.onPageHide);

        if (this.heartbeat) clearInterval(this.heartbeat);

        this.endSession();
        this.realtime?.destroy();
        this.realtime = null;
    }

    // ── Session and room ───────────────────────────────────────────────

    private async startSession(): Promise<void> {
        const body = await this.request<{
            session_token: string;
            user_id: number;
        }>('POST', '/world/session', {}, false);

        if (this.realtime && this.token) {
            this.realtime.echo.leave(sessionChannel(this.token));
        }

        this.token = body.session_token;

        if (this.destroyed) {
            this.endSession();

            return;
        }

        this.selfId = body.user_id;
        this.subscribeSession();
    }

    private join(
        preferredInstanceId?: number | null,
        slug: string = this.roomSlug,
    ): Promise<void> {
        return this.enter(`/world/rooms/${slug}/join`, {
            instance_id: preferredInstanceId ?? null,
        });
    }

    /** Anything that puts the player in a room: the room in the answer becomes the one to rejoin. */
    private async enter(
        path: string,
        body: Record<string, unknown> = {},
    ): Promise<void> {
        const previous = this.instance;
        const sent = Date.now();
        const result = await this.request<JoinBody>('POST', path, body);

        if (this.destroyed) return;

        if (previous) this.realtime?.echo.leave(previous.channel);

        this.roomSlug = result.snapshot.room.slug;
        this.applySnapshot(result.snapshot, sent);
        this.subscribeInstance();

        if (result.notice) this.bus.emit('notice', { message: result.notice });
    }

    private applySnapshot(snapshot: Snapshot, sentAtMs: number): void {
        if (!this.checkProtocol(snapshot.protocol)) return;

        // Art changed since this tab loaded its own: a fresh page draws the new map with the new art.
        if (
            this.assetsVersion !== null &&
            snapshot.assets_version !== this.assetsVersion
        ) {
            this.fail('please_reload', this.config.copy.please_reload);

            return;
        }

        this.clock.observe(snapshot.server_time_ms, sentAtMs);
        this.instance = snapshot.instance;
        this.listener.snapshot(snapshot, this.selfId);
        this.bus.emit('instance', {
            id: snapshot.instance.id,
            label: snapshot.instance.label,
            number: snapshot.instance.number,
        });
        this.bus.emit('room', {
            slug: snapshot.room.slug,
            name: snapshot.room.name,
            title: snapshot.home ? snapshot.instance.label : snapshot.room.name,
            frequency: snapshot.room.frequency,
            home: snapshot.home,
            ownHome: snapshot.home?.owner_id === this.selfId,
        });
        this.showFurniture(snapshot.furniture, true);
    }

    /** After any connection drop: replace local state with a fresh snapshot, then say we're back. */
    private async resync(): Promise<void> {
        if (!this.instance) return;

        await this.refresh();

        if (this.destroyed) return;

        this.bus.emit('connection', {
            state: 'online',
            message: this.config.copy.reconnected,
        });
    }

    /**
     * Fetches a fresh snapshot and replaces local state. Falls back to rejoining
     * (and to a new session) when the server tidied us up meanwhile.
     */
    private refresh(): Promise<void> {
        this.resyncing ??= (async () => {
            try {
                if (!this.instance) return;

                const sent = Date.now();

                try {
                    const snapshot = await this.request<Snapshot>(
                        'GET',
                        this.instancePath('snapshot'),
                    );
                    this.applySnapshot(snapshot, sent);
                } catch (error) {
                    if (
                        error instanceof RefusedError &&
                        error.reason === 'session_expired'
                    ) {
                        await this.startSession();
                        await this.join(this.instance.id);
                    } else if (
                        error instanceof RefusedError &&
                        error.reason === 'not_in_room'
                    ) {
                        await this.join(this.instance.id);
                    } else {
                        throw error;
                    }
                }
            } finally {
                this.resyncing = null;
            }
        })();

        return this.resyncing;
    }

    private startHeartbeat(): void {
        this.heartbeat = setInterval(() => {
            const sent = Date.now();

            this.request<{ server_time_ms: number }>('POST', '/world/heartbeat')
                .then((body) => this.clock.observe(body.server_time_ms, sent))
                .catch((error: unknown) => {
                    if (
                        error instanceof RefusedError &&
                        error.reason === 'session_expired'
                    ) {
                        this.resync().catch((resyncError: unknown) =>
                            this.recover(resyncError),
                        );
                    }
                });
        }, this.config.heartbeatSeconds * 1000);
    }

    private readonly onPageHide = (): void => this.destroy();

    private endSession(): void {
        if (this.token) this.http.sendOnUnload('/world/session/end');
    }

    // ── Websocket ──────────────────────────────────────────────────────

    private connectSocket(): void {
        this.realtime = new Realtime(this.config.realtime, this.http, {
            connected: (first) => {
                if (first) {
                    this.bus.emit('connection', { state: 'online' });
                } else {
                    this.resync().catch((error: unknown) =>
                        this.recover(error),
                    );
                }
            },
            reconnecting: () =>
                this.bus.emit('connection', {
                    state: 'reconnecting',
                    message: this.config.copy.reconnecting,
                }),
        });
    }

    private subscribeSession(): void {
        if (!this.realtime || !this.token) return;

        const copy = this.config.copy;

        this.realtime.echo
            .private(sessionChannel(this.token))
            .listen(SESSION_EVENTS.superseded, () =>
                this.fail('session_moved', copy.session_moved),
            )
            .listen(SESSION_EVENTS.accessRevoked, () =>
                this.fail('banned', copy.banned),
            )
            .listen(SESSION_EVENTS.warned, () =>
                this.bus.emit('notice', { message: copy.warned }),
            )
            .listen(
                SESSION_EVENTS.friendsUpdated,
                (event: FriendsUpdatedEvent) => {
                    if (event.message) {
                        this.bus.emit('notice', { message: event.message });
                    }

                    this.friends()
                        .then((friends) => this.bus.emit('friends', friends))
                        .catch(() => undefined);
                },
            )
            .listen(
                SESSION_EVENTS.instanceReassigned,
                (event: InstanceReassignedEvent) => {
                    this.bus.emit('notice', { message: event.message });
                    this.instance = null;
                    this.retrying(() =>
                        this.join(
                            event.target_instance_id,
                            event.room_slug ?? this.roomSlug,
                        ),
                    ).catch((error: unknown) => this.abort(error));
                },
            );
    }

    private subscribeInstance(): void {
        if (!this.realtime || !this.instance) return;

        const guardProtocol =
            <T extends { protocol: number }>(fn: (event: T) => void) =>
            (event: T) => {
                if (this.checkProtocol(event.protocol)) fn(event);
            };
        const listener = this.listener;

        this.realtime.echo
            .join(this.instance.channel)
            // Events broadcast between the join snapshot and this subscription are lost; catch up once subscribed.
            .subscribed(() => {
                this.refresh().catch((error: unknown) => this.recover(error));
            })
            .listen(
                ROOM_EVENTS.playerJoined,
                guardProtocol((e: PlayerJoinedEvent) => listener.joined(e)),
            )
            .listen(
                ROOM_EVENTS.playerLeft,
                guardProtocol((e: PlayerLeftEvent) => listener.left(e)),
            )
            .listen(
                ROOM_EVENTS.playerMoved,
                guardProtocol((e: PlayerMovedEvent) => listener.moved(e)),
            )
            .listen(
                ROOM_EVENTS.chatMessageSent,
                guardProtocol((e: ChatMessageSentEvent) => listener.chat(e)),
            )
            .listen(
                ROOM_EVENTS.furnitureChanged,
                guardProtocol((e: FurnitureChangedEvent) =>
                    this.showFurniture(e.furniture),
                ),
            )
            .listen(
                ROOM_EVENTS.rareCatchLanded,
                guardProtocol((e: RareCatchLandedEvent) =>
                    this.bus.emit('notice', { message: e.message }),
                ),
            )
            .listenForWhisper(WHISPERS.idle, (e: IdleWhisper) =>
                listener.idle(e.user_id, e.idle),
            );
    }

    // ── Plumbing ───────────────────────────────────────────────────────

    /** A change to the current home's arrangement. Snapshots carry their own, which the scene draws with them. */
    private showFurniture(
        placed: PlacedFurniture[],
        fromSnapshot = false,
    ): void {
        if (!fromSnapshot) this.listener.furnitureChanged(placed);

        this.bus.emit('furniture', { placed });
    }

    private fail(kind: FatalKind, message: string): void {
        this.bus.emit('fatal', { kind, message });

        // The server already ended a moved or banned session; ending it again could end the new one.
        if (kind === 'session_moved' || kind === 'banned') this.token = null;

        this.destroy();
    }

    /** After a failed resync: retry the socket when the failure may pass, otherwise give up. */
    private recover(error: unknown): void {
        if (this.destroyed) return;

        if (isTransient(error)) {
            this.realtime?.reconnect();
        } else {
            this.abort(error);
        }
    }

    /** Retries transient failures, showing the reconnecting banner meanwhile and clearing it on success. */
    private async retrying<T>(attempt: () => Promise<T>): Promise<T> {
        let retried = false;
        const result = await retryWhileTransient(
            attempt,
            () => this.destroyed,
            () => {
                retried = true;
                this.bus.emit('connection', {
                    state: 'reconnecting',
                    message: this.config.copy.reconnecting,
                });
            },
        );

        if (retried && !this.destroyed) {
            this.bus.emit('connection', { state: 'online' });
        }

        return result;
    }

    /** Refuses locally while between instances, instead of calling /world/instances/null. */
    private currentInstance(): Snapshot['instance'] {
        if (!this.instance) {
            throw new RefusedError(
                'not_in_room',
                409,
                this.config.copy.not_in_room,
            );
        }

        return this.instance;
    }

    private instancePath(action: string): string {
        return `/world/instances/${this.currentInstance().id}/${action}`;
    }

    private checkProtocol(protocol: number): boolean {
        if (protocol > PROTOCOL) {
            this.fail('please_reload', this.config.copy.please_reload);

            return false;
        }

        return true;
    }

    private async request<T>(
        method: 'GET' | 'POST' | 'DELETE',
        path: string,
        body?: Record<string, unknown>,
        handleSessionErrors = true,
    ): Promise<T> {
        const { ok, status, statusText, data } = await this.http.send(
            method,
            path,
            body,
        );

        // Every response may carry the protocol; one from a newer server means this build is outdated.
        if (
            typeof data.protocol === 'number' &&
            !this.checkProtocol(data.protocol)
        ) {
            throw new RefusedError(
                'please_reload',
                status,
                this.config.copy.please_reload,
            );
        }

        if (ok) return data as T;

        const reason = typeof data.reason === 'string' ? data.reason : 'error';
        const message =
            typeof data.message === 'string' ? data.message : statusText;

        if (handleSessionErrors && !this.destroyed) {
            if (reason === 'session_moved') this.fail('session_moved', message);
            if (reason === 'banned') this.fail('banned', message);
        }

        throw new RefusedError(reason, status, message);
    }
}
