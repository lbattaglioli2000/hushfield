import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import type { EventBus } from './bus';
import type {
    ChatMessageSentEvent,
    InstanceReassignedEvent,
    PathState,
    PlayerJoinedEvent,
    PlayerLeftEvent,
    PlayerMovedEvent,
    Snapshot,
    Tile,
} from './protocol';
import { PROTOCOL } from './protocol';

export type RealtimeConfig = {
    key: string;
    host: string;
    port: number;
    scheme: 'http' | 'https';
};

export type NetConfig = {
    baseUrl: string;
    roomSlug: string;
    heartbeatSeconds: number;
    realtime: RealtimeConfig;
    copy: Record<string, string>;
};

/** What the scene implements so the network layer can drive it. */
export type WorldListener = {
    snapshot(snapshot: Snapshot, selfId: number): void;
    joined(event: PlayerJoinedEvent): void;
    left(event: PlayerLeftEvent): void;
    moved(event: PlayerMovedEvent): void;
    chat(event: ChatMessageSentEvent): void;
    idle(userId: number, idle: boolean): void;
};

export class RefusedError extends Error {
    constructor(
        public readonly reason: string,
        public readonly status: number,
        message: string,
    ) {
        super(message);
    }
}

type ConnectionState =
    | 'initialized'
    | 'connecting'
    | 'connected'
    | 'unavailable'
    | 'failed'
    | 'disconnected';

const BACKOFF_MIN_MS = 1000;
const BACKOFF_MAX_MS = 30000;

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/**
 * Talks to Laravel (plain JSON over fetch) and Reverb (Echo). Owns the world
 * session token, the server clock offset, heartbeats, and reconnect/resync.
 */
export class WorldNet {
    private echo: Echo<'reverb'> | null = null;
    private token: string | null = null;
    private selfId = 0;
    private instance: Snapshot['instance'] | null = null;
    private clockOffsetMs = 0;
    private bestRttMs = Number.POSITIVE_INFINITY;
    private heartbeat: ReturnType<typeof setInterval> | null = null;
    private reconnectTimer: ReturnType<typeof setTimeout> | null = null;
    private attempts = 0;
    private wasConnected = false;
    private destroyed = false;
    private resyncing: Promise<void> | null = null;

    constructor(
        private readonly config: NetConfig,
        private readonly bus: EventBus,
        private readonly listener: WorldListener,
    ) {}

    /** Server time in epoch milliseconds, as best this client can tell. */
    serverNow(): number {
        return Date.now() + this.clockOffsetMs;
    }

    get instanceId(): number | null {
        return this.instance?.id ?? null;
    }

    async start(): Promise<void> {
        this.bus.emit('connection', {
            state: 'connecting',
            message: this.config.copy.entering,
        });

        // destroy() may run while any of these awaits are pending (React's dev double-mount does exactly that).
        await this.startSession();

        if (this.destroyed) return;

        this.connectSocket();
        await this.join();

        if (this.destroyed) return;

        this.startHeartbeat();
        window.addEventListener('pagehide', this.onPageHide);
    }

    async move(tile: Tile): Promise<PathState | null> {
        const body = await this.request<
            (PathState & { protocol: number }) | { dropped: true }
        >('POST', `/world/instances/${this.instanceId}/move`, {
            x: tile[0],
            y: tile[1],
        });

        return 'dropped' in body ? null : body;
    }

    async chat(text: string): Promise<{ id: number; body: string } | null> {
        const body = await this.request<
            { id: number; body: string } | { dropped: true }
        >('POST', `/world/instances/${this.instanceId}/chat`, { body: text });

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
                room_instance_id: this.instanceId,
                reason,
            },
        );

        return body.message;
    }

    /** Cosmetic only: tells others to dim this avatar. Nothing on the server changes. */
    whisperIdle(idle: boolean): void {
        if (!this.instance || !this.echo) return;

        this.echo
            .join(this.instance.channel)
            .whisper('idle', { user_id: this.selfId, idle });
    }

    /** Safe to call more than once. */
    destroy(): void {
        if (this.destroyed) return;

        this.destroyed = true;
        window.removeEventListener('pagehide', this.onPageHide);

        if (this.heartbeat) clearInterval(this.heartbeat);
        if (this.reconnectTimer) clearTimeout(this.reconnectTimer);

        this.leaveEndpoint();

        if (this.echo) {
            this.echo.leaveAllChannels();
            this.echo.disconnect();
            this.echo = null;
        }
    }

    // ── Session and room ───────────────────────────────────────────────

    private async startSession(): Promise<void> {
        const body = await this.request<{
            session_token: string;
            user_id: number;
        }>('POST', '/world/session', {}, false);

        if (this.echo && this.token) {
            this.echo.leave(`world-session.${this.token}`);
        }

        this.token = body.session_token;

        if (this.destroyed) {
            this.leaveEndpoint();

            return;
        }

        this.selfId = body.user_id;
        this.subscribeSession();
    }

    private async join(preferredInstanceId?: number | null): Promise<void> {
        const previous = this.instance;
        const sent = Date.now();
        const body = await this.request<{
            notice: string | null;
            snapshot: Snapshot;
        }>('POST', `/world/rooms/${this.config.roomSlug}/join`, {
            instance_id: preferredInstanceId ?? null,
        });

        if (previous && this.echo) this.echo.leave(previous.channel);

        this.applySnapshot(body.snapshot, sent);
        this.subscribeInstance();

        if (body.notice) this.bus.emit('notice', { message: body.notice });
    }

    private applySnapshot(snapshot: Snapshot, sentAtMs: number): void {
        if (!this.checkProtocol(snapshot.protocol)) return;

        this.observeServerTime(snapshot.server_time_ms, sentAtMs);
        this.instance = snapshot.instance;
        this.listener.snapshot(snapshot, this.selfId);
        this.bus.emit('instance', {
            label: snapshot.instance.label,
            number: snapshot.instance.number,
        });
    }

    /**
     * After any connection drop: fetch a fresh snapshot and replace local state.
     * Falls back to rejoining (and to a new session) when the server tidied us up meanwhile.
     */
    private resync(): Promise<void> {
        this.resyncing ??= (async () => {
            try {
                if (!this.instance) return;

                const sent = Date.now();

                try {
                    const snapshot = await this.request<Snapshot>(
                        'GET',
                        `/world/instances/${this.instance.id}/snapshot`,
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

                this.bus.emit('connection', {
                    state: 'online',
                    message: this.config.copy.reconnected,
                });
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
                .then((body) => this.observeServerTime(body.server_time_ms, sent))
                .catch((error: unknown) => {
                    if (
                        error instanceof RefusedError &&
                        error.reason === 'session_expired'
                    ) {
                        void this.resync();
                    }
                });
        }, this.config.heartbeatSeconds * 1000);
    }

    private readonly onPageHide = (): void => this.destroy();

    private leaveEndpoint(): void {
        if (!this.token) return;

        // keepalive lets the request outlive the page, and unlike sendBeacon it can carry headers.
        void fetch(`${this.config.baseUrl}/world/session/end`, {
            method: 'POST',
            keepalive: true,
            credentials: 'same-origin',
            headers: this.headers(),
        }).catch(() => undefined);
    }

    // ── Websocket ──────────────────────────────────────────────────────

    private connectSocket(): void {
        const { realtime } = this.config;
        const tls = realtime.scheme === 'https';

        this.echo = new Echo({
            broadcaster: 'reverb',
            Pusher,
            key: realtime.key,
            wsHost: realtime.host,
            wsPort: realtime.port,
            wssPort: realtime.port,
            forceTLS: tls,
            enabledTransports: ['ws', 'wss'],
            withoutInterceptors: true,
            authorizer: (channel: { name: string }) => ({
                authorize: (
                    socketId: string,
                    callback: (
                        error: Error | null,
                        data: { auth: string; channel_data?: string } | null,
                    ) => void,
                ) => {
                    fetch(`${this.config.baseUrl}/broadcasting/auth`, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: this.headers(),
                        body: JSON.stringify({
                            socket_id: socketId,
                            channel_name: channel.name,
                        }),
                    })
                        .then(async (response) =>
                            response.ok
                                ? callback(
                                      null,
                                      (await response.json()) as {
                                          auth: string;
                                          channel_data?: string;
                                      },
                                  )
                                : callback(
                                      new Error(`Auth ${response.status}`),
                                      null,
                                  ),
                        )
                        .catch((error: Error) => callback(error, null));
                },
            }),
        });

        this.subscribeSession();

        this.echo.connector.pusher.connection.bind(
            'state_change',
            ({ current }: { previous: ConnectionState; current: ConnectionState }) =>
                this.onSocketState(current),
        );
    }

    private onSocketState(state: ConnectionState): void {
        if (this.destroyed) return;

        if (state === 'connected') {
            if (this.reconnectTimer) clearTimeout(this.reconnectTimer);
            this.reconnectTimer = null;
            this.attempts = 0;

            if (this.wasConnected) {
                void this.resync().catch(() => this.scheduleReconnect());
            } else {
                this.bus.emit('connection', { state: 'online' });
            }

            this.wasConnected = true;

            return;
        }

        if (
            (state === 'unavailable' ||
                state === 'disconnected' ||
                state === 'failed') &&
            this.reconnectTimer === null
        ) {
            this.scheduleReconnect();
        }
    }

    /** Exponential backoff with full jitter, 1 s up to 30 s. Pusher resubscribes every channel on connect. */
    private scheduleReconnect(): void {
        if (this.destroyed || !this.echo) return;

        this.bus.emit('connection', {
            state: 'reconnecting',
            message: this.config.copy.reconnecting,
        });

        const ceiling = Math.min(
            BACKOFF_MAX_MS,
            BACKOFF_MIN_MS * 2 ** this.attempts,
        );
        const delay = BACKOFF_MIN_MS + Math.random() * (ceiling - BACKOFF_MIN_MS);
        this.attempts++;

        const pusher = this.echo.connector.pusher;

        if (pusher.connection.state !== 'disconnected') pusher.disconnect();

        this.reconnectTimer = setTimeout(() => {
            this.reconnectTimer = null;

            if (!this.destroyed && this.echo) {
                this.echo.connector.pusher.connect();
            }
        }, delay);
    }

    private subscribeSession(): void {
        if (!this.echo || !this.token) return;

        const copy = this.config.copy;

        this.echo
            .private(`world-session.${this.token}`)
            .listen('.SessionSuperseded', () =>
                this.fail('session_moved', copy.session_moved),
            )
            .listen('.WorldAccessRevoked', () =>
                this.fail('banned', copy.banned),
            )
            .listen('.PlayerWarned', () =>
                this.bus.emit('notice', { message: copy.warned }),
            )
            .listen('.InstanceReassigned', (event: InstanceReassignedEvent) => {
                this.bus.emit('notice', { message: copy.instance_closed });
                this.instance = null;
                void this.join(event.target_instance_id).catch(() => undefined);
            });
    }

    private subscribeInstance(): void {
        if (!this.echo || !this.instance) return;

        const stamp = <T extends { protocol: number }>(fn: (event: T) => void) =>
            (event: T) => {
                if (this.checkProtocol(event.protocol)) fn(event);
            };

        this.echo
            .join(this.instance.channel)
            .listen('.PlayerJoined', stamp((e: PlayerJoinedEvent) => this.listener.joined(e)))
            .listen('.PlayerLeft', stamp((e: PlayerLeftEvent) => this.listener.left(e)))
            .listen('.PlayerMoved', stamp((e: PlayerMovedEvent) => this.listener.moved(e)))
            .listen('.ChatMessageSent', stamp((e: ChatMessageSentEvent) => this.listener.chat(e)))
            .listenForWhisper('idle', (e: { user_id: number; idle: boolean }) =>
                this.listener.idle(e.user_id, e.idle),
            );
    }

    // ── Plumbing ───────────────────────────────────────────────────────

    private fail(
        kind: 'session_moved' | 'please_reload' | 'banned',
        message: string,
    ): void {
        this.bus.emit('fatal', { kind, message });
        this.token = kind === 'please_reload' ? this.token : null;
        this.destroy();
    }

    private checkProtocol(protocol: number): boolean {
        if (protocol > PROTOCOL) {
            this.fail('please_reload', this.config.copy.please_reload);

            return false;
        }

        return true;
    }

    /** Keeps the offset from the lowest-latency sample, assuming symmetric latency. */
    private observeServerTime(serverMs: number, sentAtMs: number): void {
        const receivedAt = Date.now();
        const rtt = receivedAt - sentAtMs;

        if (rtt <= this.bestRttMs * 1.5 || !Number.isFinite(this.bestRttMs)) {
            this.bestRttMs = Math.min(this.bestRttMs, rtt);
            this.clockOffsetMs = serverMs + rtt / 2 - receivedAt;
        }
    }

    private headers(): Record<string, string> {
        const headers: Record<string, string> = {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrfToken(),
        };

        if (this.token) headers['X-World-Session'] = this.token;

        const socketId = this.echo?.socketId();

        if (socketId) headers['X-Socket-ID'] = socketId;

        return headers;
    }

    private async request<T>(
        method: 'GET' | 'POST',
        path: string,
        body?: Record<string, unknown>,
        handleSessionErrors = true,
    ): Promise<T> {
        const response = await fetch(`${this.config.baseUrl}${path}`, {
            method,
            credentials: 'same-origin',
            headers: this.headers(),
            body: method === 'GET' ? undefined : JSON.stringify(body ?? {}),
        });

        const data = (await response.json().catch(() => ({}))) as Record<
            string,
            unknown
        >;

        if (response.ok) return data as T;

        const reason = typeof data.reason === 'string' ? data.reason : 'error';
        const message =
            typeof data.message === 'string' ? data.message : response.statusText;

        if (handleSessionErrors && !this.destroyed) {
            if (reason === 'session_moved') this.fail('session_moved', message);
            if (reason === 'banned') this.fail('banned', message);
        }

        throw new RefusedError(reason, response.status, message);
    }
}
