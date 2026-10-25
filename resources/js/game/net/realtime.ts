import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { backoffDelay } from './backoff';
import type { Http } from './http';

export type RealtimeConfig = {
    key: string;
    host: string;
    port: number;
    scheme: 'http' | 'https';
};

/** pusher-js connection states. */
type PusherState =
    | 'initialized'
    | 'connecting'
    | 'connected'
    | 'unavailable'
    | 'failed'
    | 'disconnected';

type AuthData = { auth: string; channel_data?: string };

export type RealtimeHandlers = {
    /** The socket (re)connected; `first` is false after a drop, when local state may be stale. */
    connected(first: boolean): void;
    /** The socket dropped and a reconnect is scheduled. */
    reconnecting(): void;
};

/**
 * The Reverb socket through Echo: channel auth over our own fetch (Echo's
 * interceptors are off), and reconnects with backoff. Pusher resubscribes every
 * channel on connect.
 */
export class Realtime {
    readonly echo: Echo<'reverb'>;
    private reconnectTimer: ReturnType<typeof setTimeout> | null = null;
    private attempts = 0;
    private wasConnected = false;
    private destroyed = false;

    constructor(
        config: RealtimeConfig,
        http: Http,
        private readonly handlers: RealtimeHandlers,
    ) {
        this.echo = new Echo({
            broadcaster: 'reverb',
            Pusher,
            key: config.key,
            wsHost: config.host,
            wsPort: config.port,
            wssPort: config.port,
            forceTLS: config.scheme === 'https',
            enabledTransports: ['ws', 'wss'],
            withoutInterceptors: true,
            authorizer: (channel: { name: string }) => ({
                authorize: (
                    socketId: string,
                    callback: (
                        error: Error | null,
                        data: AuthData | null,
                    ) => void,
                ) => {
                    http.post('/broadcasting/auth', {
                        socket_id: socketId,
                        channel_name: channel.name,
                    })
                        .then(async (response) =>
                            response.ok
                                ? callback(
                                      null,
                                      (await response.json()) as AuthData,
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

        this.echo.connector.pusher.connection.bind(
            'state_change',
            ({ current }: { previous: PusherState; current: PusherState }) =>
                this.onState(current),
        );
    }

    socketId(): string | undefined {
        return this.echo.socketId();
    }

    /** Drops the socket and reconnects after a backoff delay. */
    reconnect(): void {
        if (this.destroyed) return;

        this.handlers.reconnecting();

        const delay = backoffDelay(this.attempts);
        this.attempts++;

        const pusher = this.echo.connector.pusher;

        // Set the timer before disconnecting: the 'disconnected' state change that follows
        // must see a reconnect already scheduled, or it would schedule a second one.
        if (this.reconnectTimer) clearTimeout(this.reconnectTimer);
        this.reconnectTimer = setTimeout(() => {
            this.reconnectTimer = null;

            if (!this.destroyed) pusher.connect();
        }, delay);

        if (pusher.connection.state !== 'disconnected') pusher.disconnect();
    }

    destroy(): void {
        if (this.destroyed) return;

        this.destroyed = true;

        if (this.reconnectTimer) clearTimeout(this.reconnectTimer);

        this.echo.leaveAllChannels();
        this.echo.disconnect();
    }

    private onState(state: PusherState): void {
        if (this.destroyed) return;

        if (state === 'connected') {
            if (this.reconnectTimer) clearTimeout(this.reconnectTimer);
            this.reconnectTimer = null;
            this.attempts = 0;

            const first = !this.wasConnected;
            this.wasConnected = true;
            this.handlers.connected(first);

            return;
        }

        if (
            (state === 'unavailable' ||
                state === 'disconnected' ||
                state === 'failed') &&
            this.reconnectTimer === null
        ) {
            this.reconnect();
        }
    }
}
