/**
 * The only bridge between the game and whatever UI hosts it. React subscribes
 * and emits here; it never touches Phaser objects, and game state never lives in React.
 */
export type ConnectionState = 'connecting' | 'online' | 'reconnecting';

export type GameEvents = {
    // Game → UI
    connection: { state: ConnectionState; message?: string };
    instance: { label: string; number: number };
    notice: { message: string };
    fatal: { kind: 'session_moved' | 'please_reload' | 'banned'; message: string };
    'chat-error': { message: string };
    'report-target': {
        userId: number;
        username: string;
        messageId: number | null;
        body: string | null;
    };
    // UI → Game
    'send-chat': { body: string };
    'send-report': {
        userId: number;
        messageId: number | null;
        reason: string;
    };
    'report-result': { ok: boolean; message: string };
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
            ?.forEach((handler) => (handler as Handler<GameEvents[K]>)(payload));
    }

    clear(): void {
        this.handlers.clear();
    }
}
