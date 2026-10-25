import type { Item, Wallet } from './protocol/types';

/**
 * The only bridge between the game and whatever UI hosts it. React subscribes
 * and emits here; it never touches Phaser objects, and game state never lives in React.
 */
export type ConnectionState = 'connecting' | 'online' | 'reconnecting';

/** Why the game stopped for good. 'unavailable' covers boot failures and lost rooms. */
export type FatalKind =
    | 'session_moved'
    | 'please_reload'
    | 'banned'
    | 'unavailable';

export type GameEvents = {
    // Game → UI
    connection: { state: ConnectionState; message?: string };
    instance: { label: string; number: number };
    notice: { message: string };
    fatal: { kind: FatalKind; message: string };
    'chat-error': { message: string };
    'report-target': {
        userId: number;
        username: string;
        messageId: number | null;
        body: string | null;
    };
    /** The answer to a 'send-report'. */
    'report-result': { ok: boolean; message: string };
    /** After entering the world and after every purchase or stipend claim. */
    wallet: Wallet;
    /** The answer to a 'load-shop'. */
    shop: { items: Item[] };
    /** The answer to a 'purchase-item' or 'claim-stipend', and failures of 'load-shop'. */
    'economy-result': { ok: boolean; message: string };

    // UI → Game
    'send-chat': { body: string };
    'send-report': {
        userId: number;
        messageId: number | null;
        reason: string;
    };
    'load-shop': Record<string, never>;
    'purchase-item': { itemId: number };
    'claim-stipend': Record<string, never>;
    /** No payload: any input that keeps the player from being shown as away. */
    'input-activity': Record<string, never>;
};

type Handler<T> = (payload: T) => void;

export class EventBus {
    private handlers = new Map<keyof GameEvents, Set<Handler<never>>>();

    on<K extends keyof GameEvents>(
        event: K,
        handler: Handler<GameEvents[K]>,
    ): () => void {
        let set = this.handlers.get(event);

        if (!set) {
            set = new Set();
            this.handlers.set(event, set);
        }

        set.add(handler as Handler<never>);

        return () => set.delete(handler as Handler<never>);
    }

    emit<K extends keyof GameEvents>(event: K, payload: GameEvents[K]): void {
        this.handlers
            .get(event)
            ?.forEach((handler) =>
                (handler as Handler<GameEvents[K]>)(payload),
            );
    }

    clear(): void {
        this.handlers.clear();
    }
}
