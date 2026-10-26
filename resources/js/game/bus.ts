import type {
    FishingCatch,
    FixtureKind,
    FriendsList,
    HomeInfo,
    HomeVisitors,
    HomesList,
    Item,
    PlacedFurniture,
    TownMap,
    Wallet,
} from './protocol/types';

/**
 * The only bridge between the game and whatever UI hosts it. React subscribes
 * and emits here; it never touches Phaser objects, and game state never lives in React.
 */
export type ConnectionState = 'connecting' | 'online' | 'reconnecting';

/** Idle, asking to cast, line in and waiting, something biting, asking to reel. */
export type FishingPhase = 'idle' | 'casting' | 'waiting' | 'bite' | 'reeling';

/** Why the game stopped for good. 'unavailable' covers boot failures and lost rooms. */
export type FatalKind =
    | 'session_moved'
    | 'please_reload'
    | 'banned'
    | 'unavailable';

export type GameEvents = {
    // Game → UI
    connection: { state: ConnectionState; message?: string };
    instance: { id: number; label: string; number: number };
    /** After every snapshot: the room the player is in, and whose home it is. */
    room: {
        slug: string;
        name: string;
        /** The room's name, or "Wren's residence" in a home. */
        title: string;
        /** Where the tuning dial points, in MHz; null leaves it off-station. */
        frequency: number | null;
        home: HomeInfo | null;
        /** The player is standing in their own home and may decorate it. */
        ownHome: boolean;
    };
    /** The current home's arrangement, after every snapshot and change. Empty outside homes. */
    furniture: { placed: PlacedFurniture[] };
    /** The answer to a 'load-homes' or 'set-home-open'. */
    homes: HomesList;
    /** The answer to a 'load-town', or why it could not be had. */
    town: { map: TownMap } | { error: string };
    /** After entering the world, after every change to the list, and whenever a friend request arrives. */
    friends: FriendsList;
    /** The outcome of anything asked of the friends list. */
    'friends-result': { ok: boolean; message: string };
    /** The outcome of anything asked of the residences: travel, the door, furniture. */
    'home-result': { ok: boolean; message: string };
    notice: { message: string };
    fatal: { kind: FatalKind; message: string };
    'chat-error': { message: string };
    /** The player tapped another resident. */
    'player-selected': {
        userId: number;
        username: string;
        messageId: number | null;
        body: string | null;
    };
    /** The answer to a 'send-report'. */
    'report-result': { ok: boolean; message: string };
    /** After entering the world and after every purchase or stipend claim. */
    wallet: Wallet;
    /** The player walked onto a fixture on the map, such as the shop counter. */
    'use-fixture': { kind: FixtureKind };
    /** The answer to a 'load-shop'. */
    shop: { items: Item[]; open: boolean };
    /** The answer to a 'purchase-item' or 'claim-stipend', and failures of 'load-shop'. */
    'economy-result': { ok: boolean; message: string };
    /** Where the player's line is: see FishingPhase. */
    fishing: { phase: FishingPhase };
    /** How a reel went (a landed catch, or one that got away), or why a cast or reel was refused. */
    'fishing-result': {
        ok: boolean;
        message: string;
        catch: FishingCatch | null;
        paid: number;
    };
    /** The player walked off while their line was in the water; it is abandoned. */
    'fishing-interrupted': Record<string, never>;

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
    /** Put a line in the water, or reel the one that is in. The server judges both. */
    'cast-line': Record<string, never>;
    'reel-in': Record<string, never>;
    /** Go to a room: the residence with no instance is the player's own home. */
    travel: { slug: string; instanceId: number | null };
    'load-homes': Record<string, never>;
    /** The town map, with fresh counts, for when the Map key opens it. */
    'load-town': Record<string, never>;
    'set-home-visitors': { visitors: HomeVisitors };
    /** Fresh locations and requests, for when the friends list opens. */
    'load-friends': Record<string, never>;
    'send-friend-request': { username: string };
    'answer-friend-request': { requestId: number; accept: boolean };
    'remove-friend': { userId: number };
    'set-friend-requests': { allowed: boolean };
    /** In decorate mode taps place the armed item, or put away the piece tapped, instead of walking. */
    decorate: { active: boolean; armed: Item | null };
    /** Turn a placed piece a quarter turn clockwise, where it stands. */
    'turn-furniture': { itemId: number };
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
